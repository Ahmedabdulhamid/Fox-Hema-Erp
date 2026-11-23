<?php

namespace App\Modules\BackupRestore\Services;

use DirectoryIterator;

class FileSnapshot
{
    /** @param list<array{table: string, id: int, disk: string, path: string}>|null $references
     * @return list<array{path: string, source: string, size: int, sha256: string, mtime: int, mode: int}>
     */
    public function capture(BackupWorkspace $workspace, BackupLease $lease, ?array $references = null): array
    {
        app(CaptureProtection::class)->assertHeld($lease);
        if ($references === null) {
            throw new BackupFailure('dump_incomplete');
        }
        $roots = $this->roots();
        $before = $this->inventory($roots);
        $this->references($before, $references);
        $files = [];
        foreach ($before as $relative => $source) {
            app(CaptureProtection::class)->assertHeld($lease);
            app(BackupLeaseManager::class)->heartbeat($lease);
            $destination = $workspace->file('capture/'.$relative);
            $input = @fopen($source, 'rb');
            if ($input === false) {
                throw new BackupFailure('files_changed');
            }
            $output = app(PrivateFiles::class)->create($destination);
            $hash = hash_init('sha256');
            $size = 0;
            try {
                $stat = fstat($input);
                while (! feof($input)) {
                    $chunk = fread($input, 1024 * 1024);
                    if ($chunk === false) {
                        throw new BackupFailure('files_changed');
                    }
                    PrivateFiles::write($output, $chunk);
                    hash_update($hash, $chunk);
                    $size += strlen($chunk);
                    app(BackupLeaseManager::class)->heartbeat($lease);
                    app(CaptureProtection::class)->assertHeld($lease);
                }
                $after = fstat($input);
                if ($stat['size'] !== $size || $stat['size'] !== $after['size'] || $stat['mtime'] !== $after['mtime'] || $stat['ino'] !== $after['ino']) {
                    throw new BackupFailure('files_changed');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            $checksum = hash_final($hash);
            clearstatcache(true, $source);
            if (is_link($source) || ! is_file($source) || ! hash_equals($checksum, (string) hash_file('sha256', $source))) {
                throw new BackupFailure('files_changed');
            }
            $files[] = ['path' => $relative, 'source' => $destination, 'size' => $size, 'sha256' => $checksum, 'mtime' => $stat['mtime'], 'mode' => $stat['mode'] & 0777];
        }
        if ($before !== $this->inventory($roots)) {
            throw new BackupFailure('files_changed');
        }
        app(CaptureProtection::class)->assertHeld($lease);

        return $files;
    }

    /** @return array<string, string> */
    private function roots(): array
    {
        $roots = [];
        foreach (['public' => 'public', 'private' => 'local'] as $label => $disk) {
            $configured = config('filesystems.disks.'.$disk);
            $root = $configured['root'] ?? '';
            if (($configured['driver'] ?? null) !== 'local' || ! is_string($root) || is_link($root) || ! is_dir($root)) {
                throw new BackupFailure('unsupported_storage');
            }
            $roots[$label] = realpath($root);
        }

        return $roots;
    }

    /** @param array<string, string> $roots
     * @return array<string, string>
     */
    private function inventory(array $roots): array
    {
        $files = [];
        foreach ($roots as $label => $root) {
            $this->scan($root, $root, $label, $files);
        }
        ksort($files);

        return $files;
    }

    /** @param array<string, string> $files */
    private function scan(string $root, string $directory, string $label, array &$files): void
    {
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($root) + 1));
            BackupWorkspace::assertRelative($relative);
            $resolved = realpath($entry->getPathname());
            if ($entry->isLink() || $resolved === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
                throw new BackupFailure('unsafe_path');
            }
            if ($entry->isDir()) {
                if (! in_array($entry->getFilename(), config('backup-restore.excluded_directories'), true)) {
                    $this->scan($root, $resolved, $label, $files);
                }

                continue;
            }
            if (! $entry->isFile()) {
                throw new BackupFailure('unsafe_path');
            }
            if ($relative === '.gitignore') {
                continue;
            }
            if ($label === 'private' && ! $this->approvedPrivate($relative)) {
                if (in_array($relative, config('backup-restore.private_excluded_files'), true)) {
                    continue;
                }
                throw new BackupFailure('private_files_unclassified');
            }
            $files['files/'.$label.'/'.$relative] = $resolved;
            if (count($files) > (int) config('backup-restore.max_files')) {
                throw new BackupFailure('archive_invalid');
            }
        }
    }

    private function approvedPrivate(string $relative): bool
    {
        foreach (config('backup-restore.private_paths') as $approved) {
            BackupWorkspace::assertRelative($approved);
            if ($relative === $approved || str_starts_with($relative, rtrim($approved, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $files
     * @param  list<array{table: string, id: int, disk: string, path: string}>  $references
     */
    private function references(array $files, array $references): void
    {
        $missing = ['product_image_ids' => [], 'brand_ids' => []];
        foreach ($references as $reference) {
            if (($reference['disk'] ?? null) !== 'public' || ! in_array($reference['table'] ?? null, ['product_images', 'brands'], true)
                || ! is_int($reference['id'] ?? null) || ! is_string($reference['path'] ?? null)) {
                throw new BackupFailure('unsupported_storage');
            }
            BackupWorkspace::assertRelative($reference['path']);
            if (! isset($files['files/public/'.$reference['path']])) {
                $key = $reference['table'] === 'brands' ? 'brand_ids' : 'product_image_ids';
                if (count($missing[$key]) < 100) {
                    $missing[$key][] = $reference['id'];
                }
            }
        }
        if ($missing['product_image_ids'] !== [] || $missing['brand_ids'] !== []) {
            throw new BackupFailure('missing_media', $missing);
        }
    }
}
