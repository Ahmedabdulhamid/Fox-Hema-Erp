<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\BackupRestore\Models\BackupRun;
use Closure;
use Throwable;

class BackupVerifier
{
    public function verify(BackupRun $run, BackupWorkspace $workspace, Closure $tick): void
    {
        $encrypted = $workspace->file('readback.fhbk');
        $plain = $workspace->file('readback.plain');
        try {
            app(BackupTransport::class)->download($run, $encrypted, $tick);
            $header = app(BackupEncryption::class)->decrypt($encrypted, $plain, $tick);
            $verified = app(BackupArchive::class)->verify($plain, $tick);
            $manifest = $verified['manifest'];
            $snapshot = $run->destination_snapshot;
            if (($header['run_uuid'] ?? null) !== $run->uuid || ($manifest['run_uuid'] ?? null) !== $run->uuid
                || ($header['attempt_uuid'] ?? null) !== ($snapshot['attempt_uuid'] ?? null) || $manifest['attempt_uuid'] !== $header['attempt_uuid']
                || ($header['fence'] ?? null) !== ($snapshot['fence'] ?? null) || $manifest['fence'] !== $header['fence']
                || $header['key_id'] !== $run->encryption_key_id || ($header['manifest_checksum'] ?? null) !== $run->manifest_checksum
                || $verified['manifest_checksum'] !== $run->manifest_checksum || count($manifest['files']) !== (int) $run->file_count
                || $manifest['database']['schema_checksum'] !== $run->schema_checksum
                || ($manifest['database']['database'] ?? null) !== ($snapshot['database_name'] ?? null)) {
                throw new BackupFailure('checksum_mismatch');
            }
        } catch (Throwable $failure) {
            throw BackupFailure::sanitized($failure, 'archive_invalid');
        } finally {
            foreach ([$encrypted, $plain] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
