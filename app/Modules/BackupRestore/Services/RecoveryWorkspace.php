<?php

namespace App\Modules\BackupRestore\Services;

final class RecoveryWorkspace
{
    public function __construct(public readonly BackupWorkspace $files, public readonly string $operation, #[\SensitiveParameter] private readonly string $owner) {}

    public static function create(string $operation, #[\SensitiveParameter] string $owner): self
    {
        if (! preg_match('/\A[a-f0-9]{32}\z/D', $operation) || ! preg_match('/\A[a-f0-9]{64}\z/D', $owner)) {
            throw new BackupFailure('unsafe_path');
        }
        $root = rtrim((string) config('backup-restore.restore.root'), '/\\');
        if (! preg_match('~\A(?:[a-zA-Z]:[\\\\/]|/)~', $root) || ! is_dir(dirname($root))) {
            throw new BackupFailure('unsafe_path');
        }
        for ($probe = $root; dirname($probe) !== $probe; $probe = dirname($probe)) {
            if (is_link($probe)) {
                throw new BackupFailure('unsafe_path');
            }
        }
        $candidate = strtolower(str_replace('\\', '/', realpath($root) ?: (realpath(dirname($root)) ?: '').'/'.basename($root)));
        foreach (['filesystems.disks.public.root', 'filesystems.disks.local.root', 'backup-restore.workspace_root'] as $key) {
            $configured = (string) config($key);
            $protected = strtolower(str_replace('\\', '/', realpath($configured) ?: $configured));
            if ($protected !== '' && ($candidate === $protected || str_starts_with($candidate.'/', $protected.'/') || str_starts_with($protected.'/', $candidate.'/'))) {
                throw new BackupFailure('unsafe_path');
            }
        }
        foreach (config('backup-restore.key_files', []) as $keyFile) {
            $keyPath = is_string($keyFile) ? strtolower(str_replace('\\', '/', realpath($keyFile) ?: $keyFile)) : '';
            if ($keyPath !== '' && str_starts_with($keyPath.'/', $candidate.'/')) {
                throw new BackupFailure('unsafe_path');
            }
        }
        app(PrivateFiles::class)->directory($root);
        $path = $root.'/'.$operation;
        if (file_exists($path) || is_link($path) || ! mkdir($path, 0700)) {
            throw new BackupFailure('restore_busy');
        }
        app(PrivateFiles::class)->directory($path);
        $self = new self(new BackupWorkspace($path), $operation, $owner);
        $stream = app(PrivateFiles::class)->create($self->files->file('owner.json'));
        PrivateFiles::write($stream, json_encode(['operation' => $operation, 'owner' => $owner], JSON_THROW_ON_ERROR));
        fflush($stream);
        fsync($stream);
        fclose($stream);

        return $self;
    }

    public function assertOwned(): void
    {
        $root = realpath((string) config('backup-restore.restore.root'));
        $actual = realpath($this->files->path);
        $marker = $this->files->file('owner.json');
        if ($root === false || $actual !== $root.DIRECTORY_SEPARATOR.$this->operation || is_link($this->files->path) || ! is_file($marker)) {
            throw new BackupFailure('restore_cleanup_unverified');
        }
        app(PrivateFiles::class)->assertPrivate($actual);
        $identity = json_decode(file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);
        if (($identity['operation'] ?? null) !== $this->operation || ! hash_equals($this->owner, $identity['owner'] ?? '')) {
            throw new BackupFailure('restore_cleanup_unverified');
        }
    }

    /** @param array<string, bool|int|string> $facts */
    public function record(string $stage, array $facts = []): void
    {
        $this->assertOwned();
        $path = $this->files->file('journal.jsonl');
        if (! file_exists($path)) {
            fclose(app(PrivateFiles::class)->create($path));
        }
        $stream = fopen($path, 'ab');
        PrivateFiles::write($stream, json_encode(['stage' => $stage, 'time' => gmdate(DATE_ATOM), 'facts' => $facts], JSON_THROW_ON_ERROR)."\n");
        fflush($stream);
        fsync($stream);
        fclose($stream);
    }

    public function removeTransient(): void
    {
        $this->assertOwned();
        foreach (['artifact.fhbk', 'archive.plain', 'database', 'trusted-tables.sql', 'import.sql', 'import.sql.generated.jsonl', 'client.cnf'] as $path) {
            $this->files->remove($path);
        }
    }

    public function cleanup(): void
    {
        $this->assertOwned();
        $directory = new \DirectoryIterator($this->files->path);
        foreach ($directory as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $this->files->remove($entry->getFilename());
        }
        unset($directory, $entry);
        if (! @rmdir($this->files->path)) {
            throw new BackupFailure('private_storage_unavailable');
        }
    }
}
