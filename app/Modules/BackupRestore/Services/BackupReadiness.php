<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\BackupRestore\Models\BackupDestination;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BackupReadiness
{
    private const REQUEST_CACHE_KEY = 'backup_restore.readiness.blockers';

    private const REQUEST_DISPLAY_CACHE_KEY = 'backup_restore.readiness.display_blockers';

    private const REQUEST_CAPTURE_CACHE_KEY = 'backup_restore.readiness.capture_blockers';

    private const DISPLAY_CAPABILITY_TTL_SECONDS = 300;

    /** @return array<string, array{status: string, blockers: list<string>}> */
    public function coverage(): array
    {
        return app(BackupCoverage::class)->report();
    }

    /** @return list<string> Only stable, non-sensitive reason codes. */
    public function blockers(): array
    {
        return $this->resolveBlockers(false);
    }

    /** @return list<string> Display-only readiness; cached results never authorize an operation. */
    public function displayBlockers(): array
    {
        return $this->resolveBlockers(true);
    }

    /** @return list<string> */
    private function resolveBlockers(bool $forDisplay): array
    {
        $request = request();
        $requestCacheKey = $forDisplay ? self::REQUEST_DISPLAY_CACHE_KEY : self::REQUEST_CACHE_KEY;
        if ($request->hasSession()) {
            $cachedBlockers = $request->attributes->get($requestCacheKey);
            if (is_array($cachedBlockers)) {
                return $cachedBlockers;
            }
        }

        $reasons = $this->captureBlockers();
        try {
            $connection = app(DatabaseDumper::class)->connection();
        } catch (Throwable) {
            $blockers = array_values(array_unique([...$reasons, 'database_identity_mismatch']));
            if ($request->hasSession()) {
                $request->attributes->set($requestCacheKey, $blockers);
            }

            return $blockers;
        }
        if (! BackupDestination::query()->where('status', 'active')->where('probe_status', 'passed')->exists()) {
            $reasons[] = 'r2_not_configured';
        }
        if ($forDisplay) {
            $reasons = [...$reasons, ...$this->displayCapabilityBlockers($connection)];
        } else {
            try {
                if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
                    throw new BackupFailure('key_unavailable');
                }
                app(BackupKeyProvider::class)->withKey((string) config('backup-restore.key_id'), fn (#[\SensitiveParameter] string $key): bool => true);
            } catch (Throwable $failure) {
                $reasons[] = BackupFailure::sanitized($failure, 'key_unavailable')->getMessage();
            }
            try {
                app(DatabaseDumper::class)->client();
            } catch (Throwable $failure) {
                $reasons[] = BackupFailure::sanitized($failure, 'dump_binary_unavailable')->getMessage();
            }
        }
        $connection = config('queue.connections.'.config('backup-restore.queue_connection'), []);
        if (! in_array($connection['driver'] ?? null, ['database', 'redis'], true)
            || (int) ($connection['retry_after'] ?? 0) <= (int) config('backup-restore.job_timeout') + 30
            || (($connection['driver'] ?? null) === 'database' && ($connection['connection'] ?? config('database.default')) !== config('database.default'))) {
            $reasons[] = 'queue_unavailable';
        }
        if (! is_string(config('backup-restore.release_id')) || config('backup-restore.release_id') === '' || strlen(config('backup-restore.release_id')) > 100
            || (app(CaptureProtection::class) instanceof OnlineCaptureProtection && config('backup-restore.release_id') !== app(TrustedTreasuryTriggers::class)->releaseId())) {
            $reasons[] = 'release_unconfigured';
        }

        $blockers = array_values(array_unique($reasons));
        if ($request->hasSession()) {
            $request->attributes->set($requestCacheKey, $blockers);
        }

        return $blockers;
    }

    /** @return list<string> */
    private function displayCapabilityBlockers(Connection $connection): array
    {
        try {
            $serverVersion = (string) $connection->selectOne('SELECT VERSION() AS version')->version;
        } catch (Throwable $failure) {
            return [BackupFailure::sanitized($failure, 'dump_binary_unavailable')->getMessage()];
        }

        $keyPath = config('backup-restore.key_files.'.config('backup-restore.key_id'));
        $dumpBinary = config('backup-restore.dump_binary');
        $systemRoot = getenv('SystemRoot') ?: 'C:\\Windows';
        $fingerprint = [
            'application_path' => base_path(),
            'environment' => app()->environment(),
            'php' => PHP_VERSION_ID,
            'os' => PHP_OS_FAMILY,
            'sodium_secretstream_available' => function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push'),
            'database' => [$connection->getDriverName(), $connection->getDatabaseName(), $connection->getConfig('host'),
                $connection->getConfig('port'), $serverVersion],
            'key_id' => config('backup-restore.key_id'),
            'key_file' => $this->fileIdentity($keyPath),
            'key_exclusions' => [public_path(), config('filesystems.disks.public.root'), config('filesystems.disks.local.root'),
                config('backup-restore.workspace_root'), config('backup-restore.restore.root')],
            'app_key_fingerprint' => hash('sha256', (string) config('app.key')),
            'dump_binary' => $this->fileIdentity($dumpBinary, true),
        ];
        if (PHP_OS_FAMILY === 'Windows') {
            $fingerprint['system_root'] = $systemRoot;
            $fingerprint['windows_account'] = [getenv('USERDOMAIN') ?: null, getenv('USERNAME') ?: null];
            $fingerprint['acl_clients'] = [
                $this->fileIdentity($systemRoot.'\\System32\\whoami.exe', true),
                $this->fileIdentity($systemRoot.'\\System32\\icacls.exe', true),
            ];
        }

        $cacheKey = 'backup-readiness-display:'.hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));

        // Windows ACL changes are not reliably reflected by file metadata, so revalidate them at the TTL boundary.
        return Cache::store('file')->remember($cacheKey, self::DISPLAY_CAPABILITY_TTL_SECONDS, function (): array {
            $blockers = [];
            try {
                if (! function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
                    throw new BackupFailure('key_unavailable');
                }
                app(BackupKeyProvider::class)->withKey((string) config('backup-restore.key_id'), fn (#[\SensitiveParameter] string $key): bool => true);
            } catch (Throwable $failure) {
                $blockers[] = BackupFailure::sanitized($failure, 'key_unavailable')->getMessage();
            }
            try {
                app(DatabaseDumper::class)->client();
            } catch (Throwable $failure) {
                $blockers[] = BackupFailure::sanitized($failure, 'dump_binary_unavailable')->getMessage();
            }

            return $blockers;
        });
    }

    /** @return array{path: string|null, file: bool, link: bool, size: int|null, modified: int|null, changed: int|null, permissions: int|false, owner: int|false, group: int|false, sha256: string|null} */
    private function fileIdentity(mixed $path, bool $includeHash = false): array
    {
        if (! is_string($path) || $path === '') {
            return ['path' => null, 'file' => false, 'link' => false, 'size' => null, 'modified' => null, 'changed' => null,
                'permissions' => false, 'owner' => false, 'group' => false, 'sha256' => null];
        }

        clearstatcache(true, $path);
        $resolvedPath = realpath($path);
        $stat = @stat($path);

        return ['path' => $resolvedPath === false ? $path : $resolvedPath, 'file' => is_file($path), 'link' => is_link($path),
            'size' => is_array($stat) ? $stat['size'] : null, 'modified' => is_array($stat) ? $stat['mtime'] : null,
            'changed' => is_array($stat) ? $stat['ctime'] : null, 'permissions' => @fileperms($path),
            'owner' => @fileowner($path), 'group' => @filegroup($path),
            'sha256' => $includeHash && is_file($path) && ! is_link($path) ? @hash_file('sha256', $path) ?: null : null];
    }

    public function forgetCachedBlockers(): void
    {
        request()->attributes->remove(self::REQUEST_CACHE_KEY);
        request()->attributes->remove(self::REQUEST_DISPLAY_CACHE_KEY);
        request()->attributes->remove(self::REQUEST_CAPTURE_CACHE_KEY);
    }

    /** @return list<string> Only stable, non-sensitive reason codes. */
    public function captureBlockers(): array
    {
        $request = request();
        if ($request->hasSession()) {
            $cachedBlockers = $request->attributes->get(self::REQUEST_CAPTURE_CACHE_KEY);
            if (is_array($cachedBlockers)) {
                return $cachedBlockers;
            }
        }

        $blockers = app(CaptureProtection::class)->blockers();
        if ($request->hasSession()) {
            $request->attributes->set(self::REQUEST_CAPTURE_CACHE_KEY, $blockers);
        }

        return $blockers;
    }

    public function assertReady(): void
    {
        $this->forgetCachedBlockers();
        $blockers = $this->blockers();
        if ($blockers !== []) {
            throw new BackupFailure($blockers[0]);
        }
    }
}
