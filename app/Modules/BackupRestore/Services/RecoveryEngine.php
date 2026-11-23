<?php

namespace App\Modules\BackupRestore\Services;

use Closure;
use Throwable;

/** No application database or ORM is used by this recovery core. */
class RecoveryEngine
{
    /** @param array<string, mixed> $databaseSettings
     * @param  Closure(string, Closure): void  $download  Writes only the supplied encrypted-artifact path.
     * @return array<string, mixed>
     */
    public function restore(RecoveryArtifact $artifact, RecoveryWorkspace $workspace, string $target, #[\SensitiveParameter] string $owner, #[\SensitiveParameter] array $databaseSettings, Closure $download, Closure $heartbeat, ?Closure $progress = null): array
    {
        if (! app()->environment(['local', 'testing']) || config('backup-restore.restore.enabled') !== true) {
            throw new BackupFailure('restore_disabled');
        }
        app(RecoveryDatabase::class)->assertTarget($target, $workspace);
        $globalPath = dirname($workspace->files->path).'/recovery.lock';
        if (! file_exists($globalPath)) {
            fclose(app(PrivateFiles::class)->create($globalPath));
        }
        app(PrivateFiles::class)->assertPrivate($globalPath);
        $globalLock = fopen($globalPath, 'r+b');
        if (! flock($globalLock, LOCK_EX | LOCK_NB)) {
            fclose($globalLock);
            throw new BackupFailure('restore_busy');
        }
        $started = microtime(true);
        $tick = static function () use ($started, $heartbeat): void {
            if (microtime(true) - $started > (int) config('backup-restore.restore.timeout')) {
                throw new BackupFailure('restore_limit_exceeded');
            }
            $heartbeat();
        };
        $lock = app(PrivateFiles::class)->create($workspace->files->file('execution.lock'));
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            flock($globalLock, LOCK_UN);
            fclose($globalLock);
            throw new BackupFailure('restore_busy');
        }
        try {
            $workspace->record('downloading');
            $encrypted = $workspace->files->file('artifact.fhbk');
            $download($encrypted, $tick);
            if (is_link($encrypted) || filesize($encrypted) !== $artifact->identity['size_bytes'] || ! hash_equals($artifact->identity['ciphertext_checksum'], hash_file('sha256', $encrypted))) {
                throw new BackupFailure('checksum_mismatch');
            }
            $plain = $workspace->files->file('archive.plain');
            $header = app(BackupEncryption::class)->decrypt($encrypted, $plain, $tick);
            $verified = app(BackupArchive::class)->verify($plain, $tick);
            $artifact->authenticate($header, $verified);
            $manifest = $verified['manifest'];
            $triggerProfile = app(TrustedTreasuryTriggers::class)->authenticate($manifest);
            $workspace->record('archive_integrity_verified');
            $progress?->__invoke(['archive_integrity_verified' => true, 'isolated_restoration_completed' => false, 'isolated_restoration_verified' => false, 'production_recovery_ready' => false]);
            $this->extract($plain, $workspace, $manifest, $tick);
            $sqlSource = $workspace->files->file('database/database.sql');
            if ($triggerProfile !== null) {
                app(TrustedTreasuryTriggers::class)->prepare($sqlSource, $workspace->files->file('trusted-tables.sql'), $triggerProfile, $tick);
                $sqlSource = $workspace->files->file('trusted-tables.sql');
            }
            $counts = app(RecoverySql::class)->prepare($sqlSource, $workspace->files->file('import.sql'), $tick);
            if ($triggerProfile === null && array_intersect(array_keys($counts), ['payment_methods', 'treasury_accounts', 'treasury_entries', 'treasury_operations']) !== []) {
                throw new BackupFailure('restore_identity_mismatch');
            }
            $tick();
            $database = app(RecoveryDatabase::class)->import($databaseSettings, $target, $workspace, $owner, $tick);
            if ($triggerProfile !== null) {
                app(RecoveryDatabase::class)->installTriggers($database, $target, $workspace, $owner, $triggerProfile, $tick);
            }
            $workspace->record('isolated_restoration_completed');
            $progress?->__invoke(['archive_integrity_verified' => true, 'isolated_restoration_completed' => true, 'isolated_restoration_verified' => false, 'production_recovery_ready' => false]);
            $result = app(RestoreVerifier::class)->verify($database, $target, $workspace, $manifest, $counts, $tick);
            $workspace->record('isolated_restoration_verified', ['tables' => $result['tables_verified'], 'files' => $result['files_verified']]);
            $workspace->removeTransient();

            return $result;
        } catch (Throwable $failure) {
            $safe = BackupFailure::sanitized($failure, 'restore_failed');
            $workspace->record('failed', ['failure_code' => $safe->getMessage()]);
            $workspace->removeTransient();
            throw $safe;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            flock($globalLock, LOCK_UN);
            fclose($globalLock);
        }
    }

    /** @param array<string, mixed> $manifest */
    private function extract(string $plain, RecoveryWorkspace $workspace, array $manifest, Closure $tick): void
    {
        $input = fopen($plain, 'rb');
        try {
            if (BackupArchive::readExact($input, 6) !== "FHAR1\n") {
                throw new BackupFailure('archive_invalid');
            }
            $length = unpack('Nsize', BackupArchive::readExact($input, 4))['size'];
            if ($length > (int) config('backup-restore.max_manifest_bytes')) {
                throw new BackupFailure('archive_invalid');
            }
            $json = BackupArchive::readExact($input, $length);
            if (json_decode($json, true, flags: JSON_THROW_ON_ERROR) !== $manifest) {
                throw new BackupFailure('archive_invalid');
            }
            $total = 0;
            foreach ($manifest['files'] as $file) {
                if (array_diff(array_keys($file), ['path', 'size', 'sha256', 'mtime', 'mode']) !== []) {
                    throw new BackupFailure('archive_invalid');
                }
                BackupWorkspace::assertRelative($file['path']);
                $relative = $file['path'] === 'database/database.sql' ? $file['path'] : 'recovered/'.$file['path'];
                $probe = $workspace->files->path;
                foreach (explode('/', $relative) as $part) {
                    $probe .= '/'.$part;
                    if (is_link($probe)) {
                        throw new BackupFailure('unsafe_path');
                    }
                }
                if (($total += $file['size']) > (int) config('backup-restore.restore.max_bytes')) {
                    throw new BackupFailure('restore_limit_exceeded');
                }
                $output = app(PrivateFiles::class)->create($workspace->files->file($relative));
                $hash = hash_init('sha256');
                $remaining = $file['size'];
                try {
                    while ($remaining > 0) {
                        $bytes = BackupArchive::readExact($input, min(1048576, $remaining));
                        $remaining -= strlen($bytes);
                        hash_update($hash, $bytes);
                        PrivateFiles::write($output, $bytes);
                        $tick();
                    }
                } finally {
                    fclose($output);
                }
                if (! hash_equals($file['sha256'], hash_final($hash))) {
                    throw new BackupFailure('checksum_mismatch');
                }
            }
            if (BackupArchive::readExact($input, 7) !== "FHEND1\n" || fread($input, 1) !== '') {
                throw new BackupFailure('archive_invalid');
            }
        } finally {
            fclose($input);
        }
    }
}
