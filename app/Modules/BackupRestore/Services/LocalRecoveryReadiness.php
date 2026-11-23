<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\BackupRestore\Models\BackupDestination;
use Aws\S3\S3Client;
use Closure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

class LocalRecoveryReadiness
{
    /** @return array<string, mixed> Local metadata only: no object requests, provisioning or permission changes. */
    public function inspect(): array
    {
        $source = DB::connection();
        $database = $source->getDatabaseName();
        $isolated = app()->environment('testing') && str_starts_with($database, 'codex_public_ids_test_');
        if (! app()->environment(['local', 'testing']) || $source->getConfig('host') !== '127.0.0.1'
            || (! $isolated && ($database !== 'fox_hema_pos' || (int) $source->getConfig('port') !== 3307))) {
            throw new BackupFailure('database_identity_mismatch');
        }
        $source = app(DatabaseDumper::class)->connection();
        $server = (string) $source->selectOne('SELECT VERSION() AS version')->version;
        $checks = [];
        $checks['independent_key'] = $this->check(fn (): bool => app(BackupKeyProvider::class)->withKey((string) config('backup-restore.key_id'), fn (#[\SensitiveParameter] string $key): bool => true));
        $checks['key_format'] = ['identifier_valid' => is_string(config('backup-restore.key_id'))
            && preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D', config('backup-restore.key_id')) === 1,
            'envelope_format' => 'FHBK1', 'archive_format' => 'FHAR1', 'existing_artifact_compatibility' => 'unverified'];
        $checks['dump_client'] = $this->check(fn (): bool => app(DatabaseDumper::class)->client() !== []);
        $checks['import_client'] = $this->check(fn (): bool => $this->importClient($server));
        $checks['r2_configuration'] = $this->check(function (): bool {
            $destination = BackupDestination::query()->where('status', 'active')->latest('id')->first();
            if (! $destination || ! R2Storage::validEndpoint($destination->endpoint)
                || ! preg_match('/\A[a-z0-9][a-z0-9-]{1,61}[a-z0-9]\z/D', $destination->bucket)
                || ! is_string($destination->access_key_id) || $destination->access_key_id === ''
                || ! is_string($destination->secret_access_key) || $destination->secret_access_key === '') {
                throw new BackupFailure('r2_not_configured');
            }
            BackupWorkspace::assertRelative($destination->prefix);

            return true;
        });
        $checks['r2_live_access_and_privacy'] = ['status' => 'unverified', 'reason' => 'no_remote_operations_permitted'];
        $release = config('backup-restore.release_id');
        $checks['release_metadata'] = ['status' => is_string($release) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,99}\z/D', $release) ? 'present' : 'blocked', 'deployment_identity' => 'unverified'];
        foreach (['backup_workspace' => 'backup-restore.workspace_root', 'recovery_workspace' => 'backup-restore.restore.root'] as $name => $setting) {
            $checks[$name] = $this->filesystem(config($setting));
        }
        foreach (['public', 'local'] as $disk) {
            $root = config('filesystems.disks.'.$disk.'.root');
            $checks['media_'.$disk] = ['readable_directory' => is_string($root) && is_dir($root) && is_readable($root),
                'complete_file_readability' => 'unverified'];
        }
        $size = $source->selectOne('SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH),0) AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$database]);
        $checks['capacity'] = ['estimated_database_bytes' => (int) $size->bytes, 'media_and_transient_peak_bytes' => 'unverified', 'sufficiency' => 'unverified'];
        $queue = config('queue.connections.'.config('backup-restore.queue_connection'), []);
        $checks['backup_queue'] = ['status' => ($queue['driver'] ?? null) === 'database'
            && ($queue['connection'] ?? config('database.default')) === config('database.default')
            && ($queue['after_commit'] ?? false) === true
            && (int) ($queue['retry_after'] ?? 0) > (int) config('backup-restore.job_timeout') + 30 ? 'configured' : 'blocked',
            'shared_jobs_table' => ($queue['table'] ?? null) === config('queue.connections.database.table')];
        $checks['capture'] = ['status' => app(CaptureProtection::class)->blockers() === [] ? 'configured' : 'blocked', 'reasons' => app(CaptureProtection::class)->blockers()];
        $checks['recovery_cli'] = ['status' => is_file(app_path('Modules/BackupRestore/Console/recover.php')) && extension_loaded('pdo_mysql') && extension_loaded('sodium') && class_exists(S3Client::class) ? 'present' : 'blocked', 'isolated_target_credentials' => 'unverified', 'independent_kit' => 'unverified'];
        $gates = [];
        foreach (['capture.enabled', 'capture.accounts_approved', 'capture.instance_lock_approved', 'restore.enabled', 'automation.controller_enabled', 'automation.scheduling_approved', 'automation.retention_deletion_approved', 'automation.notification_delivery_approved', 'automation.conditional_delete_verified'] as $gate) {
            $gates[$gate] = config('backup-restore.'.$gate);
        }

        return ['identity' => ['host' => '127.0.0.1', 'port' => (int) $source->getConfig('port'), 'database' => $database, 'server' => $server],
            'checks' => $checks, 'gates' => $gates, 'manual_backup_ready' => app(BackupReadiness::class)->blockers() === [],
            'readiness' => [
                'archive_integrity' => ['classification' => 'implemented_but_unverified', 'reason' => 'read_only_inspection_does_not_execute_artifacts'],
                'isolated_restore' => ['classification' => 'blocked_by_missing_configuration', 'reason' => 'requires_independent_kit_key_client_and_isolated_target_evidence'],
                'manual_backup' => ['classification' => app(BackupReadiness::class)->blockers() === [] ? 'configured' : 'blocked_by_missing_configuration', 'reasons' => app(BackupReadiness::class)->blockers()],
                'automatic_scheduling' => ['classification' => app(BackupAutomationState::class)->blockers('schedule') === [] ? 'configured' : 'blocked_by_missing_configuration', 'reason' => 'manual_backup_and_scheduling_approval_required'],
                'retention_deletion' => ['classification' => 'blocked_by_unresolved_safety_risk', 'reason' => 'conditional_delete_scope_and_remote_semantics_unverified'],
                'production_recovery' => ['classification' => 'blocked_by_unresolved_safety_risk', 'reason' => 'no_independent_operational_recovery_evidence'],
            ],
            'backup_blockers' => app(BackupReadiness::class)->blockers(),
            'restore_available' => app(IsolatedRestoreCoordinator::class)->available(),
            'read_only' => true];
    }

    /** @return array{status: string, reason?: string} */
    private function check(Closure $check): array
    {
        try {
            return ['status' => $check() === true ? 'verified_local' : 'blocked'];
        } catch (Throwable $failure) {
            return ['status' => 'blocked', 'reason' => BackupFailure::sanitized($failure)->getMessage()];
        }
    }

    private function importClient(string $server): bool
    {
        $binary = config('backup-restore.restore.client_binary');
        if (! is_string($binary) || ! is_file($binary) || is_link($binary) || ! preg_match('~\A(?:[a-zA-Z]:[\\\\/]|/)~', $binary)) {
            throw new BackupFailure('restore_disabled');
        }
        $version = new Process([$binary, '--no-defaults', '--version']);
        $version->setTimeout(5)->run();
        $help = new Process([$binary, '--no-defaults', '--help']);
        $help->setTimeout(5)->run();
        if (! $version->isSuccessful() || ! $help->isSuccessful() || ! str_contains($server, 'MariaDB')
            || ! str_contains($version->getOutput(), 'MariaDB')
            || ! preg_match('/Distrib\s+(\d+\.\d+)/', $version->getOutput(), $client)
            || ! preg_match('/\A(?:5\.5\.5-)?(\d+\.\d+)/', $server, $database) || $client[1] !== $database[1]) {
            throw new BackupFailure('dump_incompatible');
        }
        foreach (['binary-mode', 'local-infile', 'connect-timeout', 'reconnect', 'batch'] as $flag) {
            if (! str_contains($help->getOutput(), $flag)) {
                throw new BackupFailure('dump_incompatible');
            }
        }

        return true;
    }

    /** @return array<string, mixed> Inspection does not create directories or alter ACLs. */
    private function filesystem(mixed $path): array
    {
        if (! is_string($path) || ! preg_match('~\A(?:[a-zA-Z]:[\\\\/]|/)~', $path)) {
            return ['status' => 'blocked', 'reason' => 'unsafe_path'];
        }
        if (preg_match('~(?:\A|[\\\\/])\.\.?(?:[\\\\/]|\z)~', $path)) {
            return ['status' => 'blocked', 'reason' => 'unsafe_path'];
        }
        $probe = $path;
        while (! file_exists($probe) && dirname($probe) !== $probe) {
            $probe = dirname($probe);
        }
        $ancestor = $path;
        while (dirname($ancestor) !== $ancestor) {
            if (is_link($ancestor)) {
                return ['status' => 'blocked', 'reason' => 'unsafe_path'];
            }
            $ancestor = dirname($ancestor);
        }
        if (is_link($path) || is_link($probe) || ! is_dir($probe)) {
            return ['status' => 'blocked', 'reason' => 'unsafe_path'];
        }
        $candidate = rtrim(str_replace('\\', '/', realpath($path) ?: realpath($probe).substr($path, strlen($probe))), '/');
        foreach ([public_path(), config('filesystems.disks.public.root'), config('filesystems.disks.local.root')] as $media) {
            $resolved = is_string($media) ? realpath($media) : false;
            if ($resolved === false) {
                continue;
            }
            $resolved = rtrim(str_replace('\\', '/', $resolved), '/');
            $left = PHP_OS_FAMILY === 'Windows' ? strtolower($candidate) : $candidate;
            $right = PHP_OS_FAMILY === 'Windows' ? strtolower($resolved) : $resolved;
            if ($left === $right || str_starts_with($left, $right.'/') || str_starts_with($right, $left.'/')) {
                return ['status' => 'blocked', 'reason' => 'unsafe_path'];
            }
        }
        $space = @disk_free_space($probe);
        $private = false;
        if (is_dir($path)) {
            try {
                app(PrivateFiles::class)->assertPrivate($path);
                $private = true;
            } catch (Throwable) {
                $private = false;
            }
        }

        return ['status' => is_writable($probe) && $private ? 'verified_local' : 'blocked',
            'directory_exists' => is_dir($path), 'nearest_parent_writable' => is_writable($probe), 'private_permissions_verified' => $private,
            'free_bytes' => is_float($space) ? (int) $space : $space, 'capacity_sufficiency' => 'unverified'];
    }
}
