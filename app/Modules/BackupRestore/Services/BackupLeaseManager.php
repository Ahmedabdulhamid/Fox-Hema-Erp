<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\BackupRestore\Models\BackupOperationLease;
use App\Modules\BackupRestore\Models\BackupRun;
use Closure;
use Illuminate\Support\Facades\DB;

class BackupLeaseManager
{
    private const ACTIVE = ['capturing', 'encrypting', 'uploading', 'verifying'];

    public function acquire(string $uuid): ?BackupLease
    {
        return DB::transaction(function () use ($uuid): ?BackupLease {
            DB::table('modules')->where('code', 'backup_restore')->lockForUpdate()->firstOrFail();
            $row = BackupOperationLease::query()->lockForUpdate()->find('global');
            $this->assertCompatibleOperation($row);
            $run = BackupRun::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'queued' || ($row && $row->expires_at->isFuture())) {
                return null;
            }
            if ($row && BackupRun::query()->where('uuid', $row->operation_uuid)->whereIn('status', self::ACTIVE)->exists()) {
                throw new BackupFailure('lease_expired');
            }
            $fence = ($row?->fencing_number ?? 0) + 1;
            $owner = bin2hex(random_bytes(32));
            $row ??= new BackupOperationLease(['scope' => 'global']);
            $row->fill(['operation_uuid' => $uuid, 'operation_type' => 'backup', 'owner_token' => $owner, 'fencing_number' => $fence,
                'heartbeat_at' => now(), 'expires_at' => now()->addSeconds((int) config('backup-restore.lease_seconds'))]);
            $row->save();
            $run->update(['status' => 'capturing', 'started_at' => now()]);

            return new BackupLease($uuid, $owner, $fence);
        });
    }

    public function locked(BackupLease $lease, Closure $callback): mixed
    {
        return DB::transaction(function () use ($lease, $callback): mixed {
            $row = BackupOperationLease::query()->lockForUpdate()->find('global');
            if (! $row || $row->operation_uuid !== $lease->runUuid || ! hash_equals($row->owner_token, $lease->owner)
                || $row->fencing_number !== $lease->fence || ! $row->expires_at->isFuture()) {
                throw new BackupFailure('lease_lost');
            }

            return $callback($row);
        });
    }

    /** An expired foreign lease is not evidence that restore/verification/deletion has stopped. */
    public function assertCompatibleOperation(?BackupOperationLease $lease): void
    {
        if ($lease === null || $lease->operation_type === 'backup') {
            return;
        }
        if ($lease->operation_type === 'verification' && ! $lease->expires_at->isFuture()
            && (DB::table('backup_restore_runs')->where('uuid', $lease->operation_uuid)->where('mode', 'verify_only')->where('status', 'failed')->exists()
                || DB::table('backup_restore_runs')->where('uuid', $lease->operation_uuid)->where('mode', 'verify_only')->where('status', 'verified')
                    ->where('verification_findings->isolated_restoration_verified', true)->exists())) {
            return;
        }
        if ($lease->operation_type === 'retention' && ! $lease->expires_at->isFuture()
            && DB::table('backup_deletions')->join('backup_runs', 'backup_runs.id', '=', 'backup_deletions.backup_run_id')
                ->where('backup_runs.uuid', $lease->operation_uuid)
                ->where('backup_deletions.owner_token', $lease->owner_token)->where('backup_deletions.fence', $lease->fencing_number)
                ->where(fn ($query) => $query->where(fn ($query) => $query->where('backup_deletions.status', 'deleted')->where('backup_runs.status', 'deleted'))
                    ->orWhere(fn ($query) => $query->where('backup_deletions.status', 'failed')->where('backup_runs.status', 'deletion_failed')))->exists()) {
            return;
        }
        throw new BackupFailure('lease_busy');
    }

    public function heartbeat(BackupLease $lease): void
    {
        $this->locked($lease, function (BackupOperationLease $row): void {
            $row->update(['heartbeat_at' => now(), 'expires_at' => now()->addSeconds((int) config('backup-restore.lease_seconds'))]);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function transition(BackupLease $lease, string $from, string $to, array $attributes = []): void
    {
        $next = ['capturing' => 'encrypting', 'encrypting' => 'uploading', 'uploading' => 'verifying', 'verifying' => 'available'];
        if (($next[$from] ?? null) !== $to) {
            throw new BackupFailure('backup_failed');
        }
        $this->locked($lease, function () use ($lease, $from, $to, $attributes): void {
            $run = BackupRun::query()->where('uuid', $lease->runUuid)->lockForUpdate()->firstOrFail();
            if ($run->status !== $from) {
                throw new BackupFailure('lease_lost');
            }
            $run->fill($attributes);
            $run->status = $to;
            $run->save();
        });
    }

    public function fail(BackupLease $lease, BackupFailure $failure): bool
    {
        try {
            return $this->locked($lease, fn (): bool => (bool) BackupRun::query()->where('uuid', $lease->runUuid)->whereIn('status', self::ACTIVE)
                ->update(['status' => 'failed', 'failure_code' => $failure->getMessage(), 'completed_at' => now()]));
        } catch (BackupFailure) {
            return false;
        }
    }

    public function release(BackupLease $lease): void
    {
        BackupOperationLease::query()->where('scope', 'global')->where('operation_uuid', $lease->runUuid)
            ->where('owner_token', $lease->owner)->where('fencing_number', $lease->fence)->update(['expires_at' => now()]);
    }

    /** Mark interrupted attempts terminal; never resume a partly captured artifact. */
    public function recoverExpired(): ?BackupLease
    {
        return DB::transaction(function (): ?BackupLease {
            DB::table('modules')->where('code', 'backup_restore')->lockForUpdate()->firstOrFail();
            $row = BackupOperationLease::query()->lockForUpdate()->find('global');
            if (! $row || $row->expires_at->isFuture()) {
                return null;
            }
            $changed = BackupRun::query()->where('uuid', $row->operation_uuid)->whereIn('status', self::ACTIVE)
                ->update(['status' => 'failed', 'failure_code' => 'worker_interrupted', 'completed_at' => now()]);

            if (! $changed) {
                return null;
            }

            return new BackupLease($row->operation_uuid, $row->owner_token, $row->fencing_number);
        });
    }
}
