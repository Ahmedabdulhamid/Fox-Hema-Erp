<?php

namespace App\Modules\BackupRestore\Services;

use Closure;
use Throwable;

class BackupEncryption
{
    private const MAGIC = "FHBK1\n";

    private const CHUNK = 1024 * 1024;

    /** @param array<string, mixed> $metadata */
    public function encrypt(string $inputPath, string $outputPath, string $keyId, array $metadata, Closure $tick): void
    {
        app(BackupKeyProvider::class)->withKey($keyId, function (#[\SensitiveParameter] string $key) use ($inputPath, $outputPath, $keyId, $metadata, $tick): void {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $json = json_encode(array_merge($metadata, ['version' => 1, 'key_id' => $keyId, 'chunk_bytes' => self::CHUNK]), JSON_THROW_ON_ERROR);
            if (strlen($json) > 4096) {
                throw new BackupFailure('archive_invalid');
            }
            $aad = self::MAGIC.pack('N', strlen($json)).$json.$header;
            $input = @fopen($inputPath, 'rb');
            if ($input === false) {
                throw new BackupFailure('archive_invalid');
            }
            $output = app(PrivateFiles::class)->create($outputPath);
            $success = false;
            try {
                PrivateFiles::write($output, $aad);
                while (! feof($input)) {
                    $plain = fread($input, self::CHUNK);
                    if ($plain === false) {
                        throw new BackupFailure('archive_invalid');
                    }
                    if ($plain !== '') {
                        $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, $aad);
                        PrivateFiles::write($output, pack('N', strlen($cipher)).$cipher);
                        sodium_memzero($plain);
                        $tick();
                    }
                }
                $final = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', $aad, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
                PrivateFiles::write($output, pack('N', strlen($final)).$final);
                $success = true;
            } finally {
                fclose($input);
                fclose($output);
                sodium_memzero($state);
                if (! $success && is_file($outputPath)) {
                    unlink($outputPath);
                }
            }
        });
    }

    /** @return array<string, mixed> Authenticated metadata; never returns plaintext/key bytes. */
    public function decrypt(string $inputPath, string $outputPath, Closure $tick): array
    {
        $input = @fopen($inputPath, 'rb');
        if ($input === false) {
            throw new BackupFailure('authentication_failed');
        }
        $success = false;
        $created = false;
        try {
            if (BackupArchive::readExact($input, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new BackupFailure('authentication_failed');
            }
            $length = unpack('Nsize', BackupArchive::readExact($input, 4))['size'];
            if ($length < 2 || $length > 4096) {
                throw new BackupFailure('authentication_failed');
            }
            $json = BackupArchive::readExact($input, $length);
            $metadata = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($metadata) || ($metadata['version'] ?? null) !== 1 || ($metadata['chunk_bytes'] ?? null) !== self::CHUNK || ! is_string($metadata['key_id'] ?? null)) {
                throw new BackupFailure('authentication_failed');
            }
            $header = BackupArchive::readExact($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $aad = self::MAGIC.pack('N', $length).$json.$header;
            $output = app(PrivateFiles::class)->create($outputPath);
            $created = true;
            try {
                app(BackupKeyProvider::class)->withKey($metadata['key_id'], function (#[\SensitiveParameter] string $key) use ($input, $output, $header, $aad, $tick): void {
                    $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
                    $total = 0;
                    try {
                        while (true) {
                            $size = unpack('Nsize', BackupArchive::readExact($input, 4))['size'];
                            if ($size < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $size > self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                                throw new BackupFailure('authentication_failed');
                            }
                            $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, BackupArchive::readExact($input, $size), $aad);
                            if ($pulled === false) {
                                throw new BackupFailure('authentication_failed');
                            }
                            [$plain, $tag] = $pulled;
                            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                                if ($plain !== '' || fread($input, 1) !== '') {
                                    throw new BackupFailure('authentication_failed');
                                }
                                break;
                            }
                            if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE || ($total += strlen($plain)) > (int) config('backup-restore.max_archive_bytes') + (int) config('backup-restore.max_manifest_bytes') + 64) {
                                throw new BackupFailure('authentication_failed');
                            }
                            PrivateFiles::write($output, $plain);
                            sodium_memzero($plain);
                            $tick();
                        }
                    } finally {
                        sodium_memzero($state);
                    }
                });
            } finally {
                fclose($output);
            }
            $success = true;

            return $metadata;
        } catch (Throwable $failure) {
            throw BackupFailure::sanitized($failure, 'authentication_failed');
        } finally {
            fclose($input);
            if (! $success && $created && is_file($outputPath)) {
                unlink($outputPath);
            }
        }
    }
}
