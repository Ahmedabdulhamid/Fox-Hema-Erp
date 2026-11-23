<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\BackupRestore\Models\BackupRun;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class BackupDownload
{
    public function response(User $actor, string $uuid): StreamedResponse
    {
        app(BackupAccess::class)->authorize($actor, BackupPermissions::DOWNLOAD);
        $run = BackupRun::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertAvailable($run);
        $identity = $run->getRawOriginal();
        $stream = null;
        try {
            $stream = app(PrivateFiles::class)->temporaryStream();
            app(BackupTransport::class)->downloadToStream($run, $stream, static function (): void {});
            rewind($stream);
            abort_unless(BackupArchive::readExact($stream, 6) === "FHBK1\n", 409, __('backup_restore.download_failed'));
            $length = unpack('Nsize', BackupArchive::readExact($stream, 4))['size'];
            abort_unless($length >= 2 && $length <= 4096, 409, __('backup_restore.download_failed'));
            $header = json_decode(BackupArchive::readExact($stream, $length), true, 16, JSON_THROW_ON_ERROR);
            abort_unless(is_array($header) && ($header['run_uuid'] ?? null) === $run->uuid
                && ($header['attempt_uuid'] ?? null) === $run->destination_snapshot['attempt_uuid']
                && ($header['fence'] ?? null) === ($run->destination_snapshot['fence'] ?? null)
                && ($header['key_id'] ?? null) === $run->encryption_key_id, 409, __('backup_restore.download_failed'));
            rewind($stream);
            $current = $run->fresh();
            abort_unless($current !== null, 409, __('backup_restore.download_unavailable'));
            $this->assertAvailable($current);
            abort_unless($current->getRawOriginal() === $identity, 409, __('backup_restore.download_unavailable'));
            app(BackupAccess::class)->authorize($actor, BackupPermissions::DOWNLOAD);

            return response()->streamDownload(static function () use ($stream): void {
                $previous = ignore_user_abort(true);
                try {
                    while (! feof($stream) && ! connection_aborted()) {
                        $bytes = fread($stream, 1024 * 1024);
                        if ($bytes === false) {
                            throw new BackupFailure('readback_failed');
                        }
                        echo $bytes;
                    }
                } finally {
                    fclose($stream);
                    ignore_user_abort((bool) $previous);
                }
            }, 'fox-hema-backup-'.$run->uuid.'.fhbk', [
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => (string) $run->size_bytes,
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable $failure) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($failure instanceof BackupFailure || $failure instanceof \JsonException) {
                abort(409, __('backup_restore.download_failed'));
            }
            throw $failure;
        }
    }

    private function assertAvailable(BackupRun $run): void
    {
        abort_unless($run->status === 'available'
            && in_array($run->verification_status, ['checksum_verified', 'restore_verified'], true)
            && $run->verified_at !== null && $run->completed_at !== null
            && $run->archive_format === 'fhbk-v1/fhar-v1'
            && is_string($run->encryption_key_id) && $run->encryption_key_id !== ''
            && preg_match('/\A[a-f0-9]{64}\z/D', $run->ciphertext_checksum ?? '')
            && $run->size_bytes > 0, 409, __('backup_restore.download_unavailable'));
        $snapshot = $run->destination_snapshot;
        $attempt = $snapshot['attempt_uuid'] ?? null;
        $destination = $run->destination()->firstOrFail();
        abort_unless(is_string($attempt) && Str::isUuid($attempt)
            && $run->object_key === 'runs/'.$run->uuid.'/attempt-'.$attempt.'.fhbk'
            && $destination->status === 'active'
            && ($snapshot['configuration_version'] ?? null) === $destination->configuration_version,
            409, __('backup_restore.download_unavailable'));
        foreach (['endpoint', 'bucket', 'prefix'] as $field) {
            abort_unless(($snapshot[$field] ?? null) === $destination->{$field}, 409, __('backup_restore.download_unavailable'));
        }
    }
}
