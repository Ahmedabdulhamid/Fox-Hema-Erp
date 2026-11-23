<?php

namespace App\Modules\BackupRestore\Services;

use Symfony\Component\Process\Process;
use Throwable;

class PrivateFiles
{
    private static ?string $windowsSid = null;

    /** @return resource Automatically removed when the last stream reference closes. */
    public function temporaryStream(): mixed
    {
        $stream = tmpfile();
        if ($stream === false) {
            throw new BackupFailure('private_storage_unavailable');
        }
        try {
            $path = stream_get_meta_data($stream)['uri'];
            if (PHP_OS_FAMILY === 'Windows') {
                $this->windowsAcl($path, true);
            } else {
                $this->assertPrivate($path);
            }

            return $stream;
        } catch (Throwable $failure) {
            fclose($stream);
            throw $failure;
        }
    }

    public function directory(string $path): void
    {
        if (is_link($path) || (! is_dir($path) && ! @mkdir($path, 0700, true))) {
            throw new BackupFailure('private_storage_unavailable');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $this->windowsAcl($path, true);
        } else {
            @chmod($path, 0700);
            $this->assertPrivate($path);
        }
    }

    public function assertPrivate(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || ! file_exists($path)) {
            throw new BackupFailure('private_storage_unavailable');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $this->windowsAcl($path, false);
        } elseif ((fileperms($path) & 0077) !== 0 || (function_exists('posix_geteuid') && fileowner($path) !== posix_geteuid())) {
            throw new BackupFailure('private_storage_unavailable');
        }
    }

    /** @return resource */
    public function create(string $path): mixed
    {
        if (is_link($path) || ! is_dir(dirname($path))) {
            throw new BackupFailure('unsafe_path');
        }
        $stream = @fopen($path, 'x+b');
        if ($stream === false) {
            throw new BackupFailure('private_storage_unavailable');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($path, 0600);
        }

        return $stream;
    }

    /** @param resource $stream */
    public static function write(mixed $stream, #[\SensitiveParameter] string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($stream, $bytes);
            if ($written === false || $written === 0) {
                throw new BackupFailure('private_storage_unavailable');
            }
            $bytes = substr($bytes, $written);
        }
    }

    private function windowsAcl(string $path, bool $protect): void
    {
        $metadata = null;
        try {
            $system = (getenv('SystemRoot') ?: 'C:\\Windows').'\\System32\\';
            if (self::$windowsSid === null) {
                $identity = $this->command([$system.'whoami.exe', '/user', '/fo', 'csv', '/nh']);
                $columns = str_getcsv(trim($identity->getOutput()), ',', '"', '');
                if (! isset($columns[1]) || ! preg_match('/\AS-1-\d+(?:-\d+)+\z/D', $columns[1])) {
                    throw new BackupFailure('private_storage_unavailable');
                }
                self::$windowsSid = $columns[1];
            }
            $path = realpath($path);
            if ($path === false) {
                throw new BackupFailure('private_storage_unavailable');
            }
            if ($protect) {
                $grant = is_dir($path) ? '(OI)(CI)F' : 'F';
                $this->command([$system.'icacls.exe', $path, '/inheritance:r', '/grant:r', '*'.self::$windowsSid.':'.$grant, '/q']);
            }
            $metadata = tempnam(sys_get_temp_dir(), 'fh-backup-acl-');
            if ($metadata === false) {
                throw new BackupFailure('private_storage_unavailable');
            }
            $this->command([$system.'icacls.exe', $path, '/save', $metadata, '/q']);
            $bytes = file_get_contents($metadata);
            $unicode = str_starts_with($bytes, "\xff\xfe") ? substr($bytes, 2) : $bytes;
            $text = str_contains(substr($unicode, 0, 128), "\0") ? mb_convert_encoding($unicode, 'UTF-8', 'UTF-16LE') : $unicode;
            if (! preg_match('/^D:[^\r\n]+/m', $text, $dacl) || str_contains($dacl[0], 'NO_ACCESS_CONTROL')) {
                throw new BackupFailure('private_storage_unavailable');
            }
            preg_match_all('/\(([^()]*)\)/', $dacl[0], $rules);
            $ownerAllowed = false;
            foreach ($rules[1] as $rule) {
                $fields = explode(';', $rule);
                if (count($fields) !== 6 || ! in_array($fields[0], ['A', 'D'], true)) {
                    throw new BackupFailure('private_storage_unavailable');
                }
                if ($fields[0] === 'A') {
                    if (! in_array($fields[5], [self::$windowsSid, 'SY', 'BA', 'S-1-5-18', 'S-1-5-32-544'], true)) {
                        throw new BackupFailure('private_storage_unavailable');
                    }
                    $ownerAllowed = $ownerAllowed || $fields[5] === self::$windowsSid;
                }
            }
            if (! $ownerAllowed) {
                throw new BackupFailure('private_storage_unavailable');
            }
        } catch (Throwable) {
            throw new BackupFailure('private_storage_unavailable');
        } finally {
            if (is_string($metadata) && is_file($metadata)) {
                unlink($metadata);
            }
        }
    }

    /** @param list<string> $arguments */
    private function command(array $arguments): Process
    {
        $process = new Process($arguments);
        $process->setTimeout(10)->run();
        if (! $process->isSuccessful()) {
            throw new BackupFailure('private_storage_unavailable');
        }

        return $process;
    }
}
