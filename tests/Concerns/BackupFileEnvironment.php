<?php

namespace Tests\Concerns;

use App\Modules\BackupRestore\Services\BackupKeyProvider;
use App\Modules\BackupRestore\Services\PrivateFiles;
use Illuminate\Support\Facades\File;

trait BackupFileEnvironment
{
    protected string $backupTestRoot;

    protected string $backupKeyPath;

    protected function setUpBackupFiles(): void
    {
        $this->backupTestRoot = storage_path('framework/testing/backup-engine-files-'.bin2hex(random_bytes(8)));
        app(PrivateFiles::class)->directory($this->backupTestRoot);
        foreach (['public', 'private', 'keys'] as $directory) {
            mkdir($this->backupTestRoot.'/'.$directory, 0700);
        }
        $this->backupKeyPath = $this->backupTestRoot.'/keys/v1.key';
        $stream = app(PrivateFiles::class)->create($this->backupKeyPath);
        PrivateFiles::write($stream, base64_encode(random_bytes(32)));
        fclose($stream);
        config(['backup-restore.workspace_root' => $this->backupTestRoot.'/workspaces',
            'backup-restore.key_id' => 'v1', 'backup-restore.key_files' => ['v1' => $this->backupKeyPath],
            'backup-restore.release_id' => 'isolated-test-release',
            'filesystems.disks.public.root' => $this->backupTestRoot.'/public', 'filesystems.disks.local.root' => $this->backupTestRoot.'/private']);
        app(BackupKeyProvider::class)->withKey('v1', fn (#[\SensitiveParameter] string $key): bool => true);
    }

    protected function removeBackupTestDirectory(string $path): void
    {
        $actual = realpath($path);
        $root = realpath(storage_path('framework/testing'));
        if ($actual !== false && $root !== false && str_starts_with($actual, $root.DIRECTORY_SEPARATOR)
            && (str_starts_with(basename($actual), 'backup-engine-files-') || str_starts_with(basename($actual), 'backup-engine-r2-'))) {
            File::deleteDirectory($actual);
        }
    }
}
