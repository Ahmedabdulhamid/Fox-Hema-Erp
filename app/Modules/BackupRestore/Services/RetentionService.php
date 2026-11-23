<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\BackupRestore\Models\BackupOperationLease;
use App\Modules\BackupRestore\Models\BackupPolicy;
use App\Modules\BackupRestore\Models\BackupRun;
use Illuminate\Support\Facades\DB;
use Throwable;

class RetentionService
{
    /** @return array{eligible: list<string>, preserved: int, effective: bool} No remote I/O or mutations. */
    public function preview(bool $includeExecutionState = true): array
    {
        return $this->previewForPolicy(BackupPolicy::query()->find(1), $includeExecutionState);
    }

    /** @return array{eligible: list<string>, preserved: int, effective: bool} No remote I/O or mutations. */
    public function previewForPolicy(?BackupPolicy $policy, bool $includeExecutionState = true): array
    {
        if ($policy === null || ! isset($policy->maximum_backups)) {
            return ['eligible' => [], 'preserved' => 0, 'effective' => false];
        }
        $runs = BackupRun::query()->whereIn('status', ['available', 'deletion_failed'])
            ->whereIn('verification_status', ['checksum_verified', 'restore_verified'])->whereNotNull('verified_at')
            ->orderByDesc('completed_at')->orderByDesc('id')
            ->get(['id', 'uuid', 'status', 'verification_status', 'verified_at', 'completed_at', 'legal_hold', 'retain_until']);
        $minimum = max(1, (int) $policy->minimum_verified);
        $available = $runs->where('status', 'available');
        $keep = $available->take($minimum)->pluck('id')->all();
        $eligible = [];
        $reserved = DB::table('backup_restore_runs')->whereIn('status', ['requested', 'verifying', 'verified', 'approved', 'restoring'])->pluck('backup_run_id')->all();
        foreach ($runs as $index => $run) {
            if (in_array($run->id, $keep, true) || $run->legal_hold || $run->retain_until?->isFuture()
                || in_array($run->id, $reserved, true)) {
                continue;
            }
            if ($available->count() - ($run->status === 'available' ? 1 : 0) < $minimum) {
                continue;
            }
            if ($index < (int) $policy->maximum_backups && ! $run->completed_at?->lt(now()->subDays((int) $policy->retention_days))) {
                continue;
            }
            $eligible[] = $run->uuid;
        }

        return ['eligible' => $eligible, 'preserved' => $runs->count() - count($eligible),
            'effective' => $includeExecutionState && config('backup-restore.automation.controller_enabled') === true
                && (bool) $policy->retention_requested && app(BackupAutomationState::class)->blockers('retention') === []];
    }

    public function delete(User $actor, string $uuid): void
    {
        app(BackupAccess::class)->authorize($actor, 'backups.delete');
        $this->deleteCandidate($uuid, $actor);
    }

    public function prune(int $limit = 10): int
    {
        if (config('backup-restore.automation.controller_enabled') !== true) {
            throw new BackupFailure('automation_disabled');
        }
        app(BackupAutomationState::class)->assertReady('retention');
        if ($limit < 1 || $limit > 100) {
            throw new BackupFailure('retention_ineligible');
        }
        $deleted = 0;
        foreach (array_slice($this->preview()['eligible'], 0, $limit) as $uuid) {
            $this->deleteCandidate($uuid, null);
            $deleted++;
        }

        return $deleted;
    }

    /** Recover only confirmed absence of an already claimed artifact; expiry alone cannot fence a paused worker. */
    public function recover(User $actor): int
    {
        app(DatabaseDumper::class)->connection();
        app(BackupAccess::class)->authorize($actor, 'backups.delete');
        $lease = BackupOperationLease::query()->find('global');
        if (! $lease || $lease->operation_type !== 'retention' || $lease->expires_at->isFuture()) {
            return 0;
        }
        $run = BackupRun::query()->where('uuid', $lease->operation_uuid)->where('status', 'deleting')->first();
        $row = $run ? DB::table('backup_deletions')->where('backup_run_id', $run->id)->first() : null;
        if (! $row || $row->status !== 'deleting' || ! is_string($row->etag) || $row->etag === ''
            || $row->owner_token !== $lease->owner_token || (int) $row->fence !== $lease->fencing_number) {
            return 0;
        }
        $deletion = app(BackupObjectDeletion::class);
        $identity = $deletion->identity($run);
        if (json_decode($row->object_identity, true, 8, JSON_THROW_ON_ERROR) !== $identity || ! $deletion->isMissing($run)) {
            return 0;
        }

        return DB::transaction(function () use ($actor, $lease, $run, $row, $identity): int {
            DB::table('modules')->where('code', 'backup_restore')->lockForUpdate()->firstOrFail();
            $current = BackupOperationLease::query()->lockForUpdate()->find('global');
            $currentRun = BackupRun::query()->lockForUpdate()->findOrFail($run->id);
            $currentRow = DB::table('backup_deletions')->where('backup_run_id', $run->id)->lockForUpdate()->first();
            if (! $current || ! $currentRow || $current->operation_type !== 'retention' || $current->operation_uuid !== $run->uuid
                || $current->expires_at->isFuture() || $current->owner_token !== $lease->owner_token || $current->fencing_number !== $lease->fencing_number
                || $currentRun->status !== 'deleting' || $currentRow->status !== 'deleting' || $currentRow->owner_token !== $lease->owner_token
                || (int) $currentRow->fence !== $lease->fencing_number || $currentRow->etag !== $row->etag
                || $currentRow->object_identity !== $row->object_identity || app(BackupObjectDeletion::class)->identity($currentRun) !== $identity) {
                return 0;
            }
            $currentRun->update(['status' => 'deleted', 'deleted_at' => now()]);
            DB::table('backup_deletions')->where('backup_run_id', $run->id)->update(['status' => 'deleted', 'failure_code' => null,
                'completed_at' => now(), 'updated_at' => now()]);
            $this->audit($currentRun, 'backups.retention_deleted', $actor);

            return 1;
        });
    }

    private function deleteCandidate(string $uuid, ?User $actor): void
    {
        app(DatabaseDumper::class)->connection();
        app(BackupAutomationState::class)->assertReady('retention');
        $claim = DB::transaction(function () use ($uuid, $actor): ?array {
            DB::table('modules')->where('code', 'backup_restore')->lockForUpdate()->firstOrFail();
            $policy = BackupPolicy::query()->lockForUpdate()->findOrFail(1);
            $run = BackupRun::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($run->status === 'deleted') {
                return null;
            }
            if (! $policy->retention_requested || ! in_array($uuid, $this->preview(false)['eligible'], true)) {
                throw new BackupFailure('retention_ineligible');
            }
            $identity = app(BackupObjectDeletion::class)->identity($run);
            $lease = BackupOperationLease::query()->lockForUpdate()->find('global');
            app(BackupLeaseManager::class)->assertCompatibleOperation($lease);
            if ($lease && ($lease->expires_at->isFuture()
                || BackupRun::query()->where('uuid', $lease->operation_uuid)->whereIn('status', ['queued', 'capturing', 'encrypting', 'uploading', 'verifying', 'deleting'])->exists())) {
                throw new BackupFailure('lease_busy');
            }
            $token = bin2hex(random_bytes(32));
            $fence = ($lease?->fencing_number ?? 0) + 1;
            $existing = DB::table('backup_deletions')->where('backup_run_id', $run->id)->lockForUpdate()->first();
            if ($existing && (json_decode($existing->object_identity, true, 8, JSON_THROW_ON_ERROR) !== $identity || $existing->status === 'deleting')) {
                throw new BackupFailure('retention_identity_mismatch');
            }
            $lease ??= new BackupOperationLease(['scope' => 'global']);
            $lease->fill(['operation_uuid' => $run->uuid, 'operation_type' => 'retention', 'owner_token' => $token,
                'fencing_number' => $fence, 'heartbeat_at' => now(), 'expires_at' => now()->addMinutes(5)])->save();
            DB::table('backup_deletions')->updateOrInsert(['backup_run_id' => $run->id], ['status' => 'deleting', 'owner_token' => $token,
                'fence' => $fence, 'attempts' => ($existing?->attempts ?? 0) + 1, 'object_identity' => json_encode($identity, JSON_THROW_ON_ERROR),
                'failure_code' => null, 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()]);
            $run->update(['status' => 'deleting']);
            $this->audit($run, 'backups.retention_started', $actor);

            return ['run' => $run, 'token' => $token, 'fence' => $fence, 'etag' => $existing?->etag];
        });
        if ($claim === null) {
            return;
        }
        $failure = null;
        try {
            app(BackupObjectDeletion::class)->delete($claim['run'], $claim['etag'], function (string $etag) use ($claim): void {
                DB::transaction(function () use ($claim, $etag): void {
                    $this->owned($claim);
                    DB::table('backup_deletions')->where('backup_run_id', $claim['run']->id)->update(['etag' => $etag]);
                });
            });
        } catch (Throwable $exception) {
            $failure = BackupFailure::sanitized($exception, 'retention_failed');
        }
        DB::transaction(function () use ($claim, $failure, $actor): void {
            $this->owned($claim);
            $claim['run']->update(['status' => $failure ? 'deletion_failed' : 'deleted', 'deleted_at' => $failure ? null : now()]);
            DB::table('backup_deletions')->where('backup_run_id', $claim['run']->id)->update(['status' => $failure ? 'failed' : 'deleted',
                'failure_code' => $failure?->getMessage(), 'completed_at' => $failure ? null : now(), 'updated_at' => now()]);
            BackupOperationLease::query()->where('scope', 'global')->where('owner_token', $claim['token'])->update(['expires_at' => now()]);
            $this->audit($claim['run'], $failure ? 'backups.retention_failed' : 'backups.retention_deleted', $actor);
            if ($failure) {
                app(BackupAlerts::class)->record('retention_failed', $claim['run']->uuid, $failure->getMessage());
            }
        });
        if ($failure) {
            throw $failure;
        }
    }

    /** @param array{run: BackupRun, token: string, fence: int, etag: ?string} $claim */
    private function owned(array $claim): void
    {
        DB::table('modules')->where('code', 'backup_restore')->lockForUpdate()->firstOrFail();
        $row = DB::table('backup_deletions')->where('backup_run_id', $claim['run']->id)->lockForUpdate()->first();
        $lease = BackupOperationLease::query()->lockForUpdate()->find('global');
        if (! $row || ! $lease || $row->status !== 'deleting' || $row->owner_token !== $claim['token']
            || $lease->owner_token !== $claim['token'] || (int) $lease->fencing_number !== $claim['fence']) {
            throw new BackupFailure('lease_lost');
        }
    }

    private function audit(BackupRun $run, string $event, ?User $actor): void
    {
        app(AuditLogger::class)->record($event, metadata: ['run_uuid' => $run->uuid, 'scope' => 'global'], actor: $actor);
    }
}
