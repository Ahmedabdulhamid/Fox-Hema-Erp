<?php

namespace App\Modules\BackupRestore\Services;

use DirectoryIterator;

class BackupWorkspace
{
    public function __construct(public readonly string $path) {}

    public static function forLease(BackupLease $lease, bool $create = true): self
    {
        $root = rtrim((string) config('backup-restore.workspace_root'), '/\\');
        if ($root === '' || ! preg_match('/\A[a-f0-9-]{36}\z/D', $lease->runUuid) || ! preg_match('/\A[a-f0-9]{64}\z/D', $lease->owner) || $lease->fence < 1 || is_link($root)) {
            throw new BackupFailure('unsafe_path');
        }
        if (! preg_match('~\A(?:[a-zA-Z]:[\\\\/]|/)~', $root) || dirname($root) === $root) {
            throw new BackupFailure('unsafe_path');
        }
        foreach (['public', 'local'] as $disk) {
            $media = realpath((string) config('filesystems.disks.'.$disk.'.root'));
            $candidate = realpath($root) ?: ((realpath(dirname($root)) ?: dirname($root)).DIRECTORY_SEPARATOR.basename($root));
            if ($media !== false && ($candidate === $media || str_starts_with($candidate, $media.DIRECTORY_SEPARATOR) || str_starts_with($media, $candidate.DIRECTORY_SEPARATOR))) {
                throw new BackupFailure('unsafe_path');
            }
        }
        $path = $root.DIRECTORY_SEPARATOR.$lease->runUuid.'-'.$lease->fence.'-'.$lease->owner;
        if ($create) {
            if (file_exists($path)) {
                throw new BackupFailure('unsafe_path');
            }
            app(PrivateFiles::class)->directory($root);
            app(PrivateFiles::class)->directory($path);
        }

        return new self($path);
    }

    public function file(string $relative): string
    {
        self::assertRelative($relative);
        $path = $this->path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $parent = dirname($path);
        $base = realpath($this->path);
        $probe = $parent;
        while (! file_exists($probe) && dirname($probe) !== $probe) {
            $probe = dirname($probe);
        }
        $resolved = realpath($probe);
        if ($base === false || is_link($probe) || $resolved === false || ($resolved !== $base && ! str_starts_with($resolved, $base.DIRECTORY_SEPARATOR))) {
            throw new BackupFailure('unsafe_path');
        }
        if (! is_dir($parent) && ! @mkdir($parent, 0700, true)) {
            throw new BackupFailure('private_storage_unavailable');
        }
        $actual = realpath($parent);
        if (is_link($path) || $base === false || $actual === false || ($actual !== $base && ! str_starts_with($actual, $base.DIRECTORY_SEPARATOR))) {
            throw new BackupFailure('unsafe_path');
        }

        return $path;
    }

    public static function assertRelative(string $path): void
    {
        if ($path === '' || strlen($path) > 1024 || ! preg_match('//u', $path) || preg_match('/[\x00-\x1f\x7f\\\\:%]/u', $path)
            || str_starts_with($path, '/') || array_intersect(explode('/', $path), ['', '.', '..']) !== []) {
            throw new BackupFailure('unsafe_path');
        }
        foreach (explode('/', $path) as $part) {
            if (rtrim($part, '. ') !== $part || preg_match('/[<>"|?*]/u', $part) || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|\z)/iD', $part)) {
                throw new BackupFailure('unsafe_path');
            }
        }
    }

    public function cleanup(): void
    {
        $root = realpath((string) config('backup-restore.workspace_root'));
        $path = realpath($this->path);
        if ($path === false) {
            return;
        }
        if ($root === false || is_link($this->path) || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)
            || ! preg_match('/\A[a-f0-9-]{36}-[1-9][0-9]*-[a-f0-9]{64}\z/D', basename($path))) {
            throw new BackupFailure('unsafe_path');
        }
        $this->removeTree($path, $path);
    }

    public function remove(string $relative): void
    {
        BackupWorkspace::assertRelative($relative);
        $path = $this->path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        $root = realpath($this->path);
        $resolved = realpath($path);
        if ($root === false || $resolved === false || is_link($path) || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
            throw new BackupFailure('unsafe_path');
        }
        if (is_dir($path)) {
            $this->removeTree($path, $root);
        } elseif (! @unlink($path)) {
            throw new BackupFailure('private_storage_unavailable');
        }
    }

    private function removeTree(string $path, string $root): void
    {
        foreach (new DirectoryIterator($path) as $item) {
            if ($item->isDot()) {
                continue;
            }
            if ($item->isLink() || ! $item->isDir()) {
                $resolved = realpath($item->getPathname());
                if (! $item->isLink() && ($resolved === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR))) {
                    throw new BackupFailure('unsafe_path');
                }
                if (! @unlink($item->getPathname())) {
                    throw new BackupFailure('private_storage_unavailable');
                }
            } else {
                $resolved = realpath($item->getPathname());
                if ($resolved === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
                    throw new BackupFailure('unsafe_path');
                }
                $this->removeTree($resolved, $root);
            }
        }
        if (! @rmdir($path)) {
            throw new BackupFailure('private_storage_unavailable');
        }
    }
}
