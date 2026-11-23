<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\BackupRestore\Jobs\VerifyIsolatedRestore;
use App\Modules\BackupRestore\Models\BackupOperationLease;
use App\Modules\BackupRestore\Models\BackupRestoreRun;
use App\Modules\BackupRestore\Models\BackupRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class IsolatedRestoreCoordinator
{
    public function available(): bool
    {
        $database = config('backup-restore.restore.database');
        $client = config('backup-restore.restore.client_binary');

        if (! app()->environment(['local', 'testing']) || config('backup-restore.restore.enabled') !== true
            || ! is_array($database) || ($database['host'] ?? null) !== '127.0.0.1'
            || ! is_int($database['port'] ?? null) || $database['port'] < 1 || $database['port'] > 65535
            || ! is_string($database['username'] ?? null) || $database['username'] === ''
            || ! is_string($database['password'] ?? null)
            || ! is_string($client) || ! is_file($client) || is_link($client)
            || ! Schema::hasColumns('backup_restore_runs', ['request_key', 'artifact_identity', 'owner_token', 'attempts', 'heartbeat_at'])) {
            return false;
        }

        if (app()->environment('local')) {
            $source = config('database.connections.'.config('database.default'), []);
            if (is_array($source) && (int) ($source['port'] ?? 0) === $database['port']) {
                return false;
            }
        }

        try {
            app(RecoveryDatabase::class)->administrator($database);
        } catch (BackupFailure|\PDOException) {
            return false;
        }

        return true;
    }

    public function request(User $actor, string $backupUuid, string $requestUuid): BackupRestoreRun
    {
        $this->authorize($actor);
        app(DatabaseDumper::class)->connection();
        if (! $this->available()) {
            throw new BackupFailure('restore_disabled');
        }
        if (! Str::isUuid($requestUuid) || ! Str::isUuid($backupUuid)) {
            throw new BackupFailure('restore_identity_mismatch');
        }

        return DB::transaction(function () use ($actor, $backupUuid, $requestUuid): BackupRestoreRun {
            DB::table('modules')->where('code', 'backup_restore')->lockForUpdate()->firstOrFail();
            $key = hash('sha256', $actor->getKey().':'.$requestUuid);
            $existing = BackupRestoreRun::query()->where('request_key', $key)->first();
            if ($existing) {
                if ($existing->backupRun->uuid !== $backupUuid) {
                    throw new BackupFailure('restore_identity_mismatch');
                }

                return $existing;
            }
            $lease = BackupOperationLease::query()->lockForUpdate()->find('global');
            app(BackupLeaseManager::class)->assertCompatibleOperation($lease);
            if (($lease && $lease->expires_at->isFuture()) || BackupRun::query()->whereIn('status', ['queued', 'capturing', 'encrypting', 'uploading', 'verifying'])->exists()
                || BackupRestoreRun::query()->whereIn('status', ['requested', 'verifying', 'restoring'])->exists()) {
                throw new BackupFailure('restore_busy');
            }
            $backup = BackupRun::query()->where('uuid', $backupUuid)->lockForUpdate()->firstOrFail();
            $artifact = RecoveryArtifact::fromRun($backup);
            $uuid = (string) Str::uuid();
            $owner = bin2hex(random_bytes(32));
            $run = BackupRestoreRun::query()->create(['uuid' => $uuid, 'backup_run_id' => $backup->id, 'mode' => 'verify_only', 'status' => 'requested',
                'requested_by' => $actor->id, 'request_key' => $key, 'owner_token' => $owner, 'artifact_checksum' => $backup->ciphertext_checksum,
                'artifact_identity' => $artifact->identity, 'target_identifier' => 'fh_recovery_'.str_replace('-', '', $uuid), 'attempts' => 0, 'heartbeat_at' => now()]);
            $lease ??= new BackupOperationLease(['scope' => 'global']);
            $lease->fill(['operation_uuid' => $uuid, 'operation_type' => 'verification', 'owner_token' => $owner,
                'fencing_number' => ($lease->fencing_number ?? 0) + 1, 'heartbeat_at' => now(), 'expires_at' => now()->addSeconds((int) config('backup-restore.restore.timeout') + 60)])->save();
            $this->audit($run, 'backups.restore_requested');
            DB::afterCommit(function () use ($uuid): void {
                try {
                    VerifyIsolatedRestore::dispatch($uuid)->onConnection(config('backup-restore.queue_connection'))->onQueue('backup-restores');
                } catch (Throwable) {
                    $changed = BackupRestoreRun::query()->where('uuid', $uuid)->where('status', 'requested')
                        ->update(['status' => 'failed', 'failure_code' => 'queue_dispatch_failed', 'completed_at' => now()]);
                    if ($changed) {
                        BackupOperationLease::query()->where('scope', 'global')->where('operation_uuid', $uuid)
                            ->where('operation_type', 'verification')->update(['expires_at' => now()]);
                        $this->audit(BackupRestoreRun::query()->where('uuid', $uuid)->firstOrFail(), 'backups.restore_failed');
                    }
                    throw new BackupFailure('queue_dispatch_failed');
                }
            });

            return $run;
        });
    }

    public function execute(string $uuid): void
    {
        app(DatabaseDumper::class)->connection();
        $run = DB::transaction(function () use ($uuid): ?BackupRestoreRun {
            $run = BackupRestoreRun::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'requested' || $run->mode !== 'verify_only' || $run->attempts !== 0) {
                return null;
            }
            $run->update(['status' => 'verifying', 'attempts' => 1, 'started_at' => now(), 'heartbeat_at' => now()]);

            return $run;
        });
        if ($run === null) {
            return;
        }
        try {
            $this->authorizeRequester($run);
            if (! $this->available()) {
                throw new BackupFailure('restore_disabled');
            }
            $this->heartbeat($run);
            $artifact = RecoveryArtifact::fromRun($run->backupRun);
            if ($artifact->identity !== $run->artifact_identity) {
                throw new BackupFailure('restore_identity_mismatch');
            }
            $workspace = RecoveryWorkspace::create(str_replace('-', '', $uuid), $run->owner_token);
            $result = app(RecoveryEngine::class)->restore($artifact, $workspace, $run->target_identifier, $run->owner_token, config('backup-restore.restore.database'),
                fn (string $path, \Closure $tick) => app(BackupTransport::class)->download($run->backupRun, $path, $tick), fn () => $this->heartbeat($run),
                fn (array $facts) => BackupRestoreRun::query()->whereKey($run->id)->where('status', 'verifying')->update(['verification_findings' => json_encode($facts, JSON_THROW_ON_ERROR)]));
            app(RecoveryDatabase::class)->cleanup(config('backup-restore.restore.database'), $run->target_identifier, $workspace, $run->owner_token);
            $workspace->cleanup();
            DB::transaction(function () use ($run, $result): void {
                $current = BackupRestoreRun::query()->lockForUpdate()->findOrFail($run->id);
                if ($current->status !== 'verifying') {
                    throw new BackupFailure('restore_interrupted');
                }
                $current->update(['status' => 'verified', 'verification_findings' => $result, 'completed_at' => now()]);
                BackupOperationLease::query()->where('scope', 'global')->where('operation_uuid', $run->uuid)->where('owner_token', $run->owner_token)->update(['expires_at' => now()]);
                $this->audit($current, 'backups.restore_verified');
            });
        } catch (Throwable $failure) {
            $safe = BackupFailure::sanitized($failure, 'restore_failed');
            $changed = BackupRestoreRun::query()->whereKey($run->id)->where('status', 'verifying')
                ->update(['status' => 'failed', 'failure_code' => $safe->getMessage(), 'completed_at' => now()]);
            if ($changed) {
                BackupOperationLease::query()->where('scope', 'global')->where('operation_uuid', $run->uuid)
                    ->where('owner_token', $run->owner_token)->where('operation_type', 'verification')->update(['expires_at' => now()]);
            }
            $this->audit($run->fresh(), 'backups.restore_failed');
            throw $safe;
        }
    }

    /** Interrupted imports are quarantined; an expired lease is never permission to restart or delete. */
    public function recoverInterrupted(User $actor): int
    {
        $this->authorize($actor);
        app(DatabaseDumper::class)->connection();
        if (! $this->available()) {
            throw new BackupFailure('restore_disabled');
        }
        $recovered = 0;
        foreach (BackupRestoreRun::query()->whereIn('status', ['requested', 'verifying'])->where('heartbeat_at', '<', now()->subSeconds((int) config('backup-restore.restore.timeout') + 60))->get() as $run) {
            $changed = BackupRestoreRun::query()->whereKey($run->id)->where('status', $run->status)->where('heartbeat_at', $run->heartbeat_at)
                ->update(['status' => 'failed', 'failure_code' => 'restore_interrupted', 'completed_at' => now()]);
            if ($changed) {
                $this->audit($run->fresh(), 'backups.restore_interrupted');
                $recovered++;
            }
        }

        return $recovered;
    }

    private function heartbeat(BackupRestoreRun $run): void
    {
        $this->authorizeRequester($run);
        if (config('backup-restore.restore.enabled') !== true || ! BackupRestoreRun::query()->whereKey($run->id)->where('mode', 'verify_only')->where('status', 'verifying')->exists()
            || ! BackupOperationLease::query()->where('scope', 'global')->where('operation_uuid', $run->uuid)->where('owner_token', $run->owner_token)->where('operation_type', 'verification')->where('expires_at', '>', now())->exists()) {
            throw new BackupFailure('restore_interrupted');
        }
        if ($run->heartbeat_at === null || $run->heartbeat_at->lt(now()->subSeconds(5))) {
            BackupRestoreRun::query()->whereKey($run->id)->update(['heartbeat_at' => now()]);
            $run->heartbeat_at = now();
        }
    }

    private function authorizeRequester(BackupRestoreRun $run): void
    {
        $actor = User::query()->find($run->requested_by);
        foreach (['backups.restore.request', 'backups.verify'] as $permission) {
            if (! app(BackupAccess::class)->allows($actor, $permission)) {
                throw new BackupFailure('permission_revoked');
            }
        }
    }

    private function authorize(User $actor): void
    {
        foreach (['backups.restore.request', 'backups.verify'] as $permission) {
            app(BackupAccess::class)->authorize($actor, $permission);
        }
    }

    private function audit(BackupRestoreRun $run, string $event): void
    {
        app(AuditLogger::class)->record($event, metadata: ['restore_uuid' => $run->uuid, 'status' => $run->status,
            'failure_code' => in_array($run->failure_code, BackupFailure::CODES, true) ? $run->failure_code : null, 'scope' => 'isolated'], actor: User::query()->find($run->requested_by));
    }
}
