<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\BackupRestore\Jobs\CreateFullBackup;
use App\Modules\BackupRestore\Models\BackupDestination;
use App\Modules\BackupRestore\Models\BackupOperationLease;
use App\Modules\BackupRestore\Models\BackupPolicy;
use App\Modules\BackupRestore\Models\BackupRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class BackupCoordinator
{
    /** Never clear legacy quarantine or clean its artifacts on heartbeat age alone. */
    private function assertLegacyCaptureResolved(): void
    {
        if (! Schema::hasTable('backup_write_barriers') || ! Schema::hasTable('backup_write_operations')) {
            throw new BackupFailure('write_coordination_unavailable');
        }
        if (DB::table('backup_write_barriers')->where('state', '<>', 'open')->exists()
            || DB::table('backup_write_operations')->exists()) {
            throw new BackupFailure('write_barrier_recovery_required');
        }
    }

    public function request(User $actor, string $requestUuid): BackupRun
    {
        app(DatabaseDumper::class)->connection();
        app(BackupAccess::class)->authorize($actor, 'backups.run');
        if (! Str::isUuid($requestUuid)) {
            throw new BackupFailure('backup_failed');
        }
        $idempotency = 'manual:'.$actor->getKey().':'.$requestUuid;

        return $this->enqueue($actor, $idempotency);
    }

    public function requestScheduled(BackupPolicy $policy, CarbonImmutable $scheduledFor): BackupRun
    {
        app(DatabaseDumper::class)->connection();
        app(BackupAutomationState::class)->assertReady('schedule');

        return $this->enqueue(null, 'scheduled:'.$policy->id.':'.$scheduledFor->setTimezone($policy->timezone)->format('Y-m-d'), $policy, $scheduledFor);
    }

    private function enqueue(?User $actor, string $idempotency, ?BackupPolicy $policy = null, ?CarbonImmutable $scheduledFor = null): BackupRun
    {
        $existing = BackupRun::query()->where('idempotency_key', $idempotency)->first();
        if ($existing) {
            return $existing;
        }
        if ($policy === null) {
            app(BackupReadiness::class)->assertReady();
        }

        return DB::transaction(function () use ($actor, $idempotency, $policy, $scheduledFor): BackupRun {
            DB::table('modules')->where('code', 'backup_restore')->lockForUpdate()->firstOrFail();
            $existing = BackupRun::query()->where('idempotency_key', $idempotency)->first();
            if ($existing) {
                return $existing;
            }
            if ($policy !== null) {
                $current = BackupPolicy::query()->lockForUpdate()->findOrFail($policy->id);
                if (! $current->schedule_requested || $current->configuration_version !== $policy->configuration_version
                    || ! DB::table('backup_schedule_occurrences')->where('policy_id', $policy->id)
                        ->where('scheduled_for', $scheduledFor->utc()->format('Y-m-d H:i:s'))->where('policy_version', $policy->configuration_version)
                        ->whereIn('status', ['pending', 'blocked'])->exists()) {
                    throw new BackupFailure('schedule_invalid');
                }
            }
            app(BackupLeaseManager::class)->assertCompatibleOperation(BackupOperationLease::query()->lockForUpdate()->find('global'));
            if (BackupRun::query()->whereIn('status', ['queued', 'capturing', 'encrypting', 'uploading', 'verifying'])->exists()
                || BackupOperationLease::query()->where('scope', 'global')->where('expires_at', '>', now())->exists()) {
                throw new BackupFailure('lease_busy');
            }
            $destination = BackupDestination::query()->where('status', 'active')->where('probe_status', 'passed')->sole();
            if ($policy !== null && $policy->destination_id !== $destination->id) {
                throw new BackupFailure('r2_not_configured');
            }
            $run = BackupRun::query()->create(['destination_id' => $destination->id, 'trigger' => $policy === null ? 'manual' : 'scheduled', 'idempotency_key' => $idempotency,
                'policy_id' => $policy?->id, 'scheduled_for' => $scheduledFor, 'requested_by' => $actor?->getKey(), 'status' => 'queued', 'verification_status' => 'pending',
                'destination_snapshot' => ['endpoint' => $destination->endpoint, 'bucket' => $destination->bucket, 'prefix' => $destination->prefix,
                    'configuration_version' => $destination->configuration_version, 'database_name' => app(DatabaseDumper::class)->connection()->getDatabaseName()]]);
            $this->audit($run, $policy === null ? 'backups.manual_requested' : 'backups.scheduled_requested');
            if ($policy !== null) {
                DB::table('backup_schedule_occurrences')->where('policy_id', $policy->id)
                    ->where('scheduled_for', $scheduledFor->utc()->format('Y-m-d H:i:s'))
                    ->update(['status' => 'dispatched', 'run_uuid' => $run->uuid, 'failure_code' => null, 'updated_at' => now()]);
            }
            $uuid = $run->uuid;
            DB::afterCommit(function () use ($uuid): void {
                try {
                    CreateFullBackup::dispatch($uuid)->onConnection(config('backup-restore.queue_connection'))
                        ->onQueue(config('backup-restore.queue'))->afterCommit();
                } catch (Throwable) {
                    $run = BackupRun::query()->where('uuid', $uuid)->firstOrFail();
                    if (BackupRun::query()->whereKey($run->id)->where('status', 'queued')->update(['status' => 'failed', 'failure_code' => 'queue_dispatch_failed', 'completed_at' => now()])) {
                        $this->audit($run->fresh(), 'backups.failed');
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
        $run = BackupRun::query()->where('uuid', $uuid)->firstOrFail();
        if ($run->status !== 'queued') {
            return;
        }
        $lease = null;
        $workspace = null;
        $leases = app(BackupLeaseManager::class);
        try {
            $actor = $run->requested_by ? User::query()->find($run->requested_by) : null;
            if ($run->trigger === 'scheduled') {
                app(BackupAutomationState::class)->assertReady('schedule');
                if (! $run->policy?->schedule_requested || ! DB::table('backup_schedule_occurrences')
                    ->where('run_uuid', $run->uuid)->where('status', 'dispatched')->exists()) {
                    throw new BackupFailure('schedule_invalid');
                }
            } elseif (! app(BackupAccess::class)->allows($actor, 'backups.run')) {
                throw new BackupFailure('permission_revoked');
            }
            app(BackupReadiness::class)->assertReady();
            $destination = $run->destination()->firstOrFail();
            if ($destination->status !== 'active' || $destination->configuration_version !== ($run->destination_snapshot['configuration_version'] ?? null)) {
                throw new BackupFailure('r2_not_configured');
            }
            $lease = $leases->acquire($uuid);
            if ($lease === null) {
                return;
            }
            $workspace = BackupWorkspace::forLease($lease);
            $attempt = (string) Str::uuid();
            $leases->locked($lease, function () use ($run, $attempt, $lease): void {
                $snapshot = $run->destination_snapshot;
                $snapshot['attempt_uuid'] = $attempt;
                $snapshot['fence'] = $lease->fence;
                $run->update(['object_key' => 'runs/'.$run->uuid.'/attempt-'.$attempt.'.fhbk', 'destination_snapshot' => $snapshot]);
            });
            $tick = fn () => $leases->heartbeat($lease);
            $captured = app(CaptureProtection::class)->capture($lease, function () use ($workspace, $lease): array {
                app(CaptureProtection::class)->assertHeld($lease);
                $dump = app(DatabaseDumper::class)->dump($workspace, $lease);
                $files = app(FileSnapshot::class)->capture($workspace, $lease, $dump['media_references']);
                app(CaptureProtection::class)->assertHeld($lease);

                return ['dump' => $dump, 'files' => $files, 'mode' => app(CaptureProtection::class)->mode()];
            });
            $dump = $captured['dump'];
            $leases->locked($lease, function () use ($run, $dump): void {
                $snapshot = $run->fresh()->destination_snapshot;
                $snapshot['media_reference_version'] = 1;
                $snapshot['media_references'] = array_values(array_unique(array_map(
                    static fn (array $reference): string => 'files/public/'.$reference['path'], $dump['media_references'])));
                $run->update(['destination_snapshot' => $snapshot]);
            });
            $keyId = (string) config('backup-restore.key_id');
            $leases->transition($lease, 'capturing', 'encrypting', ['captured_at' => now(), 'schema_checksum' => $dump['schema_checksum'],
                'consistency_mode' => $captured['mode'], 'application_version' => config('backup-restore.release_id'), 'archive_format' => 'fhbk-v1/fhar-v1', 'encryption_key_id' => $keyId]);
            $files = [['path' => 'database/database.sql', 'source' => $dump['path'], 'size' => $dump['size'], 'sha256' => $dump['sha256'], 'mtime' => filemtime($dump['path']), 'mode' => 0600], ...$captured['files']];
            $archive = app(BackupArchive::class)->build($workspace, $files, ['run_uuid' => $uuid, 'attempt_uuid' => $attempt, 'fence' => $lease->fence,
                'captured_at' => now()->utc()->toIso8601String(), 'release_id' => config('backup-restore.release_id'), 'composer_lock_checksum' => hash_file('sha256', base_path('composer.lock')),
                'consistency_mode' => $captured['mode'], 'database' => array_diff_key($dump, ['path' => true, 'size' => true, 'sha256' => true]),
                'restore_tested' => false, 'offline_pos_client_queues_included' => false], $tick);
            $encrypted = $workspace->file('artifact.fhbk');
            app(BackupEncryption::class)->encrypt($archive['path'], $encrypted, $keyId, ['run_uuid' => $uuid, 'attempt_uuid' => $attempt,
                'fence' => $lease->fence, 'manifest_checksum' => $archive['manifest_checksum']], $tick);
            $workspace->remove('archive.plain');
            $workspace->remove('database.sql');
            $workspace->remove('capture');
            $leases->transition($lease, 'encrypting', 'uploading', ['manifest_checksum' => $archive['manifest_checksum'], 'file_count' => $archive['file_count'],
                'ciphertext_checksum' => hash_file('sha256', $encrypted), 'size_bytes' => filesize($encrypted)]);
            $run = $run->fresh();
            app(BackupTransport::class)->upload($run, $encrypted, $lease, $tick);
            $leases->transition($lease, 'uploading', 'verifying');
            $run = $run->fresh();
            app(BackupVerifier::class)->verify($run, $workspace, $tick);
            $leases->locked($lease, function () use ($leases, $lease, $run): void {
                $leases->transition($lease, 'verifying', 'available', ['verification_status' => 'checksum_verified', 'verified_at' => now(), 'completed_at' => now()]);
                $this->audit($run->fresh(), 'backups.available');
            });
        } catch (Throwable $failure) {
            $safe = BackupFailure::sanitized($failure);
            if ($lease) {
                try {
                    $leases->locked($lease, function () use ($leases, $lease, $safe, $run): void {
                        if ($leases->fail($lease, $safe)) {
                            $snapshot = $run->fresh()->destination_snapshot;
                            $snapshot['failure_evidence'] = $safe->diagnostics();
                            $run->update(['destination_snapshot' => $snapshot]);
                            $this->audit($run->fresh(), 'backups.failed');
                        }
                    });
                } catch (BackupFailure) {
                    // A stale worker must never change its successor's state.
                }
            } elseif (BackupRun::query()->whereKey($run->id)->where('status', 'queued')->update(['status' => 'failed', 'failure_code' => $safe->getMessage(), 'completed_at' => now()])) {
                $this->audit($run->fresh(), 'backups.failed');
            }
        } finally {
            try {
                $this->assertLegacyCaptureResolved();
                $workspace?->cleanup();
            } catch (BackupFailure) {
                $this->cleanupFlag($run->uuid, true);
            } finally {
                if ($lease) {
                    $leases->release($lease);
                }
            }
        }
    }

    /** @return array{recovered: int, cleaned_workspaces: int, multipart_abort_failures: int} */
    public function recover(User $actor, int $limit = 10): array
    {
        app(DatabaseDumper::class)->connection();
        app(BackupAccess::class)->authorize($actor, 'backups.verify');
        if ($limit < 1 || $limit > 100) {
            throw new BackupFailure('backup_failed');
        }
        $this->assertLegacyCaptureResolved();
        $leases = app(BackupLeaseManager::class);
        $expired = $leases->recoverExpired();
        $recovered = 0;
        if (app(BackupAccess::class)->allows($actor, 'backups.delete')) {
            $recovered += app(RetentionService::class)->recover($actor);
        }
        $aborts = 0;
        if ($expired) {
            $run = BackupRun::query()->where('uuid', $expired->runUuid)->first();
            if ($run?->status === 'failed') {
                $recovered++;
                $this->audit($run, 'backups.interrupted_recovered');
            }
        }
        $stale = BackupRun::query()->where('status', 'queued')->where('created_at', '<=', now()->subSeconds((int) config('backup-restore.queue_stale_seconds')))->orderBy('id')->limit($limit)->get();
        foreach ($stale as $run) {
            if (BackupRun::query()->whereKey($run->id)->where('status', 'queued')->update(['status' => 'failed', 'failure_code' => 'queue_dispatch_failed', 'completed_at' => now()])) {
                $recovered++;
                $this->audit($run->fresh(), 'backups.failed');
            }
        }
        $interruptedUploads = BackupRun::query()->where('status', 'failed')->whereNotNull('destination_snapshot->multipart_upload_id')->orderBy('id')->limit($limit)->get();
        foreach ($interruptedUploads as $run) {
            if (app(BackupTransport::class)->abortInterrupted($run)) {
                $snapshot = $run->destination_snapshot;
                unset($snapshot['multipart_upload_id']);
                $run->update(['destination_snapshot' => $snapshot]);
            } else {
                $aborts++;
            }
        }
        $cleaned = 0;
        $inspected = 0;
        $root = config('backup-restore.workspace_root');
        if (is_string($root) && is_dir($root) && ! is_link($root)) {
            foreach (new \DirectoryIterator($root) as $entry) {
                if ($entry->isDot()) {
                    continue;
                }
                if ($inspected++ >= $limit) {
                    break;
                }
                if (! $entry->isDir() || $entry->isLink() || ! preg_match('/\A([a-f0-9-]{36})-([1-9][0-9]*)-([a-f0-9]{64})\z/D', $entry->getFilename(), $match)) {
                    continue;
                }
                $current = BackupOperationLease::query()->find('global');
                if ($current && $current->expires_at->isFuture() && $current->operation_uuid === $match[1] && $current->fencing_number === (int) $match[2] && hash_equals($current->owner_token, $match[3])) {
                    continue;
                }
                (new BackupWorkspace($entry->getPathname()))->cleanup();
                $this->cleanupFlag($match[1], false);
                $cleaned++;
            }
        }

        return ['recovered' => $recovered, 'cleaned_workspaces' => $cleaned, 'multipart_abort_failures' => $aborts];
    }

    private function audit(BackupRun $run, string $event): void
    {
        app(AuditLogger::class)->record($event, metadata: ['run_uuid' => $run->uuid, 'status' => $run->status,
            'failure_code' => in_array($run->failure_code, BackupFailure::CODES, true) ? $run->failure_code : null, 'scope' => 'global'], actor: $run->requested_by ? User::query()->find($run->requested_by) : null);
        if (in_array($event, ['backups.available', 'backups.failed', 'backups.interrupted_recovered'], true)) {
            app(BackupAlerts::class)->forRun($run);
        }
    }

    private function cleanupFlag(string $uuid, bool $required): void
    {
        DB::transaction(function () use ($uuid, $required): void {
            $run = BackupRun::query()->where('uuid', $uuid)->whereIn('status', ['failed', 'available'])->lockForUpdate()->first();
            if (! $run) {
                return;
            }
            $snapshot = $run->destination_snapshot;
            if ($required) {
                $snapshot['cleanup_required'] = true;
            } else {
                unset($snapshot['cleanup_required']);
            }
            $run->update(['destination_snapshot' => $snapshot]);
            if ($required) {
                $this->audit($run, 'backups.cleanup_required');
            }
        });
    }
}
