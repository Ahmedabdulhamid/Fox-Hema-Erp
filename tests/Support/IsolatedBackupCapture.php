<?php

namespace Tests\Support;

use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\BackupLease;
use App\Modules\BackupRestore\Services\BackupLeaseManager;
use App\Modules\BackupRestore\Services\CaptureProtection;
use App\Modules\BackupRestore\Services\MariaDbDdlLock;
use App\Modules\BackupRestore\Services\R2Storage;
use Closure;
use Illuminate\Support\Facades\DB;

/** Test-owned, single-producer fixtures only; never attests operational writer exclusion. */
final class IsolatedBackupCapture implements CaptureProtection
{
    public bool $held = false;

    public ?Closure $onCheck = null;

    /** Only test-owned append-only publication; never attests operational writer exclusion. */
    public bool $allowAdditionalMedia = false;

    public ?MariaDbDdlLock $schemaLock = null;

    private ?BackupLease $lease = null;

    public function __construct(private readonly string $database, private readonly string $mediaRoot) {}

    public function blockers(): array
    {
        $this->assertIsolated();

        return [];
    }

    public function mode(): string
    {
        $this->assertIsolated();
        if (! $this->held) {
            throw new BackupFailure('capture_protection_unavailable');
        }

        return 'isolated_test';
    }

    public function capture(BackupLease $lease, Closure $capture): mixed
    {
        return $this->schemaLock === null ? $this->captureIsolated($lease, $capture)
            : $this->schemaLock->during(fn (): mixed => $this->captureIsolated($lease, $capture));
    }

    private function captureIsolated(BackupLease $lease, Closure $capture): mixed
    {
        $this->assertIsolated();
        if ($this->held || DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()
            || ! app(R2Storage::class) instanceof FakeBackupR2Storage) {
            throw new BackupFailure('capture_protection_unavailable');
        }
        app(BackupLeaseManager::class)->heartbeat($lease);
        $media = $this->mediaDigests();
        $this->lease = $lease;
        $this->held = true;
        try {
            $result = $capture();
            $this->assertHeld($lease);
            $after = $this->mediaDigests();
            if ($this->allowAdditionalMedia ? array_diff_assoc($media, $after) !== [] : $media !== $after) {
                throw new BackupFailure('files_changed');
            }

            return $result;
        } finally {
            $this->held = false;
            $this->lease = null;
        }
    }

    public function assertHeld(BackupLease $lease): void
    {
        $this->assertIsolated();
        if (! $this->held || $this->lease?->runUuid !== $lease->runUuid
            || $this->lease?->owner !== $lease->owner || $this->lease?->fence !== $lease->fence) {
            throw new BackupFailure('capture_protection_unavailable');
        }
        app(BackupLeaseManager::class)->heartbeat($lease);
        $this->schemaLock?->assertHeld();
        if ($this->onCheck) {
            ($this->onCheck)();
        }
    }

    private function assertIsolated(): void
    {
        $source = DB::connection();
        if (! app()->environment('testing') || ! str_starts_with($this->database, 'codex_public_ids_test_')
            || $source->getDatabaseName() !== $this->database || $source->getConfig('host') !== '127.0.0.1'
            || (int) $source->getConfig('port') !== 33316 || $source->getConfig('url') || $source->getConfig('unix_socket')
            || $source->getConfig('read') || $source->getConfig('write') || $source->getConfig('options')) {
            throw new BackupFailure('capture_protection_unavailable');
        }
        $actual = $source->selectOne('SELECT DATABASE() AS db, @@port AS port');
        if ($actual->db !== $this->database || (int) $actual->port !== 33316) {
            throw new BackupFailure('capture_protection_unavailable');
        }
        $testing = realpath(storage_path('framework/testing'));
        $root = realpath($this->mediaRoot);
        if ($root === false || $testing === false || dirname($root) !== $testing || is_link($this->mediaRoot)
            || ! preg_match('/\Abackup-engine-files-[a-f0-9]{16}\z/D', basename($root))) {
            throw new BackupFailure('capture_protection_unavailable');
        }
        foreach (['public' => 'public', 'local' => 'private'] as $disk => $directory) {
            $path = config('filesystems.disks.'.$disk.'.root');
            if (config('filesystems.disks.'.$disk.'.driver') !== 'local' || ! is_string($path)
                || is_link($path) || realpath($path) !== $root.DIRECTORY_SEPARATOR.$directory) {
                throw new BackupFailure('capture_protection_unavailable');
            }
        }
    }

    /** @return array<string, string> Fixture stability check, not external-writer exclusion. */
    private function mediaDigests(): array
    {
        $files = [];
        foreach (['public', 'private'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->mediaRoot.'/'.$directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isLink() || ! $file->isFile()) {
                    throw new BackupFailure('unsafe_path');
                }
                $hash = hash_file('sha256', $file->getPathname());
                if ($hash === false) {
                    throw new BackupFailure('files_changed');
                }
                $files[$file->getPathname()] = $hash;
            }
        }
        ksort($files);

        return $files;
    }
}
