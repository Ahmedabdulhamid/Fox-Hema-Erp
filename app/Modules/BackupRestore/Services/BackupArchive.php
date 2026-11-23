<?php

namespace App\Modules\BackupRestore\Services;

use Closure;

class BackupArchive
{
    private const MAGIC = "FHAR1\n";

    private const END = "FHEND1\n";

    /** @param list<array<string, mixed>> $files
     * @param  array<string, mixed>  $metadata
     * @return array{path: string, manifest_checksum: string, file_count: int}
     */
    public function build(BackupWorkspace $workspace, array $files, array $metadata, Closure $tick): array
    {
        $manifest = array_merge($metadata, ['format' => 'fox-hema-full', 'version' => 1,
            'files' => array_map(fn (array $file): array => array_diff_key($file, ['source' => true]), $files)]);
        $this->validateManifest($manifest);
        $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > (int) config('backup-restore.max_manifest_bytes')) {
            throw new BackupFailure('archive_invalid');
        }
        $path = $workspace->file('archive.plain');
        $output = app(PrivateFiles::class)->create($path);
        try {
            PrivateFiles::write($output, self::MAGIC.pack('N', strlen($json)).$json);
            foreach ($files as $file) {
                $input = @fopen($file['source'], 'rb');
                if ($input === false) {
                    throw new BackupFailure('archive_invalid');
                }
                try {
                    $hash = hash_init('sha256');
                    $remaining = $file['size'];
                    while ($remaining > 0) {
                        $chunk = self::readExact($input, min(1024 * 1024, $remaining));
                        hash_update($hash, $chunk);
                        PrivateFiles::write($output, $chunk);
                        $remaining -= strlen($chunk);
                        $tick();
                    }
                    if (fread($input, 1) !== '' || ! hash_equals($file['sha256'], hash_final($hash))) {
                        throw new BackupFailure('checksum_mismatch');
                    }
                } finally {
                    fclose($input);
                }
            }
            PrivateFiles::write($output, self::END);
        } finally {
            fclose($output);
        }

        return ['path' => $path, 'manifest_checksum' => hash('sha256', $json), 'file_count' => count($files)];
    }

    /** @return array{manifest: array<string, mixed>, manifest_checksum: string} */
    public function verify(string $path, Closure $tick): array
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new BackupFailure('archive_invalid');
        }
        try {
            if (self::readExact($stream, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new BackupFailure('archive_invalid');
            }
            $length = unpack('Nsize', self::readExact($stream, 4))['size'];
            if ($length < 2 || $length > (int) config('backup-restore.max_manifest_bytes')) {
                throw new BackupFailure('archive_invalid');
            }
            $json = self::readExact($stream, $length);
            $manifest = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($manifest)) {
                throw new BackupFailure('archive_invalid');
            }
            $this->validateManifest($manifest);
            foreach ($manifest['files'] as $file) {
                $remaining = $file['size'];
                $hash = hash_init('sha256');
                while ($remaining > 0) {
                    $chunk = self::readExact($stream, min(1024 * 1024, $remaining));
                    hash_update($hash, $chunk);
                    $remaining -= strlen($chunk);
                    $tick();
                }
                if (! hash_equals($file['sha256'], hash_final($hash))) {
                    throw new BackupFailure('checksum_mismatch');
                }
            }
            if (self::readExact($stream, strlen(self::END)) !== self::END || fread($stream, 1) !== '') {
                throw new BackupFailure('archive_invalid');
            }

            return ['manifest' => $manifest, 'manifest_checksum' => hash('sha256', $json)];
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string, mixed> $manifest */
    private function validateManifest(array $manifest): void
    {
        if (($manifest['format'] ?? null) !== 'fox-hema-full' || ($manifest['version'] ?? null) !== 1
            || ! is_array($manifest['files'] ?? null) || count($manifest['files']) < 1 || count($manifest['files']) > (int) config('backup-restore.max_files') + 1
            || ($manifest['files'][0]['path'] ?? null) !== 'database/database.sql' || ($manifest['files'][0]['size'] ?? 0) < 1
            || ! preg_match('/\A[a-f0-9-]{36}\z/D', $manifest['run_uuid'] ?? '') || ! preg_match('/\A[a-f0-9-]{36}\z/D', $manifest['attempt_uuid'] ?? '')
            || ! is_int($manifest['fence'] ?? null) || $manifest['fence'] < 1 || ! is_string($manifest['release_id'] ?? null) || $manifest['release_id'] === ''
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $manifest['database']['schema_checksum'] ?? '') || ! is_array($manifest['database']['migrations'] ?? null)
            || ! in_array($manifest['consistency_mode'] ?? null, ['globally_quiesced', 'isolated_test', 'online_transactional'], true)
            || (($manifest['consistency_mode'] ?? null) === 'isolated_test' && ! app()->environment('testing'))) {
            throw new BackupFailure('archive_invalid');
        }
        $seen = [];
        $total = 0;
        foreach ($manifest['files'] as $file) {
            if (! is_array($file) || ! is_string($file['path'] ?? null)) {
                throw new BackupFailure('archive_invalid');
            }
            BackupWorkspace::assertRelative($file['path']);
            $canonical = mb_strtolower($file['path'], 'UTF-8');
            if (isset($seen[$canonical]) || ($file['path'] !== 'database/database.sql' && ! str_starts_with($file['path'], 'files/public/') && ! str_starts_with($file['path'], 'files/private/'))
                || ! is_int($file['size'] ?? null) || $file['size'] < 0 || $file['size'] > (int) config('backup-restore.max_archive_bytes') - $total
                || ! preg_match('/\A[a-f0-9]{64}\z/D', $file['sha256'] ?? '') || ! is_int($file['mtime'] ?? null) || ! is_int($file['mode'] ?? null)
                || $file['mode'] < 0 || $file['mode'] > 0777) {
                throw new BackupFailure('archive_invalid');
            }
            $seen[$canonical] = true;
            $total += $file['size'];
        }
    }

    /** @param resource $stream */
    public static function readExact(mixed $stream, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = fread($stream, $length - strlen($bytes));
            if ($chunk === false || $chunk === '') {
                throw new BackupFailure('archive_invalid');
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }
}
