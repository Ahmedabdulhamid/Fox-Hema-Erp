<?php

namespace App\Modules\BackupRestore\Services;

use Closure;
use Throwable;

class BackupKeyProvider
{
    public function withKey(string $id, Closure $callback): mixed
    {
        $path = config('backup-restore.key_files.'.$id);
        if (! preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D', $id) || ! is_string($path) || ! is_file($path) || is_link($path) || filesize($path) > 128) {
            throw new BackupFailure('key_unavailable');
        }
        try {
            app(PrivateFiles::class)->assertPrivate($path);
            $key = base64_decode(trim((string) file_get_contents($path)), true);
            if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
                throw new BackupFailure('key_unavailable');
            }
            $appKey = (string) config('app.key');
            $appKey = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;
            if (is_string($appKey) && hash_equals($appKey, $key)) {
                throw new BackupFailure('key_not_independent');
            }
            foreach ([public_path(), config('filesystems.disks.public.root'), config('filesystems.disks.local.root'), config('backup-restore.workspace_root'), config('backup-restore.restore.root')] as $excluded) {
                $root = is_string($excluded) ? realpath($excluded) : false;
                $resolved = str_replace('\\', '/', (string) realpath($path));
                $root = $root === false ? false : rtrim(str_replace('\\', '/', $root), '/');
                if (PHP_OS_FAMILY === 'Windows') {
                    $resolved = strtolower($resolved);
                    $root = $root === false ? false : strtolower($root);
                }
                if ($root !== false && ($resolved === $root || str_starts_with($resolved, $root.'/'))) {
                    throw new BackupFailure('key_unavailable');
                }
            }

            return $callback($key);
        } catch (Throwable $failure) {
            throw BackupFailure::sanitized($failure, 'key_unavailable');
        } finally {
            if (isset($key) && is_string($key)) {
                sodium_memzero($key);
            }
        }
    }
}
