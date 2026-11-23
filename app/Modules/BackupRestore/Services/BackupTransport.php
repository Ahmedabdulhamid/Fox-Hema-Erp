<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\BackupRestore\Models\BackupRun;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Closure;
use Throwable;

class BackupTransport
{
    private const PART = 8 * 1024 * 1024;

    public function upload(BackupRun $run, string $path, BackupLease $lease, Closure $tick): void
    {
        $uploadId = null;
        $input = null;
        try {
            [$client, $bucket, $key] = $this->context($run);
            if ($client->doesObjectExistV2($bucket, $key) || filesize($path) > self::PART * 10000) {
                throw new BackupFailure('upload_failed');
            }
            $tick();
            $created = $client->createMultipartUpload(['Bucket' => $bucket, 'Key' => $key, 'ContentType' => 'application/octet-stream',
                'Metadata' => ['format' => 'fhbk-v1', 'sha256' => $run->ciphertext_checksum, 'application' => 'fox-hema-erp',
                    'run-uuid' => $run->uuid, 'attempt-uuid' => $run->destination_snapshot['attempt_uuid']]]);
            $uploadId = (string) $created['UploadId'];
            if ($uploadId === '') {
                throw new BackupFailure('upload_failed');
            }
            app(BackupLeaseManager::class)->locked($lease, function () use ($run, $uploadId): void {
                $snapshot = $run->destination_snapshot;
                $snapshot['multipart_upload_id'] = $uploadId;
                $run->update(['destination_snapshot' => $snapshot]);
            });
            $input = fopen($path, 'rb');
            $parts = [];
            while (! feof($input)) {
                $bytes = fread($input, self::PART);
                if ($bytes === false) {
                    throw new BackupFailure('upload_failed');
                }
                if ($bytes === '') {
                    break;
                }
                $tick();
                $number = count($parts) + 1;
                $part = $client->uploadPart(['Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId, 'PartNumber' => $number, 'Body' => $bytes, 'ContentLength' => strlen($bytes)]);
                $parts[] = ['PartNumber' => $number, 'ETag' => (string) $part['ETag']];
                $tick();
            }
            $tick();
            $client->completeMultipartUpload(['Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId, 'MultipartUpload' => ['Parts' => $parts]]);
            $uploadId = null;
            $tick();
            app(BackupLeaseManager::class)->locked($lease, function () use ($run): void {
                $snapshot = $run->destination_snapshot;
                unset($snapshot['multipart_upload_id']);
                $run->update(['destination_snapshot' => $snapshot]);
            });
        } catch (Throwable $failure) {
            if ($uploadId !== null && isset($client, $bucket, $key)) {
                try {
                    $client->abortMultipartUpload(['Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId]);
                } catch (Throwable) {
                    // The durable upload identity and bucket lifecycle cover interrupted aborts.
                }
            }
            throw BackupFailure::sanitized($failure, 'upload_failed');
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }
    }

    public function download(BackupRun $run, string $path, Closure $tick): void
    {
        [$client, $bucket, $key] = $this->context($run);
        $this->downloadObject($client, $bucket, $key, (int) $run->size_bytes, $run->ciphertext_checksum, $path, $tick);
    }

    public function downloadObject(S3Client $client, string $bucket, string $key, int $expectedSize, string $checksum, string $path, Closure $tick): void
    {
        $output = null;
        $success = false;
        try {
            $output = app(PrivateFiles::class)->create($path);
            $this->receive($client, $bucket, $key, $expectedSize, $checksum, $output, $tick);
            $success = true;
        } finally {
            if (is_resource($output)) {
                fclose($output);
                if (! $success && is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /** @param resource $output Empty private seekable stream, owned and closed by the caller. */
    public function downloadToStream(BackupRun $run, mixed $output, Closure $tick): void
    {
        [$client, $bucket, $key] = $this->context($run);
        $this->receive($client, $bucket, $key, (int) $run->size_bytes, $run->ciphertext_checksum, $output, $tick);
    }

    /** @param resource $output */
    private function receive(S3Client $client, string $bucket, string $key, int $expectedSize, string $checksum, mixed $output, Closure $tick): void
    {
        $body = null;
        try {
            $head = $client->headObject(['Bucket' => $bucket, 'Key' => $key]);
            if ($expectedSize < 1 || $expectedSize > (int) config('backup-restore.max_archive_bytes') + (int) config('backup-restore.max_manifest_bytes') + 1048576 || (int) $head['ContentLength'] !== $expectedSize) {
                throw new BackupFailure('checksum_mismatch');
            }
            $response = $client->getObject(['Bucket' => $bucket, 'Key' => $key, '@http' => ['stream' => true, 'read_timeout' => R2Storage::REQUEST_TIMEOUT_SECONDS]]);
            $body = $response['Body'];
            $hash = hash_init('sha256');
            $size = 0;
            while (! $body->eof()) {
                $chunk = $body->read(1024 * 1024);
                if ($chunk === '' && ! $body->eof()) {
                    throw new BackupFailure('readback_failed');
                }
                $size += strlen($chunk);
                if ($size > $expectedSize) {
                    throw new BackupFailure('checksum_mismatch');
                }
                PrivateFiles::write($output, $chunk);
                hash_update($hash, $chunk);
                $tick();
            }
            if ($size !== $expectedSize || ! hash_equals($checksum, hash_final($hash))) {
                throw new BackupFailure('checksum_mismatch');
            }
        } catch (Throwable $failure) {
            throw BackupFailure::sanitized($failure, 'readback_failed');
        } finally {
            $body?->close();
        }
    }

    /** Aborts only this failed attempt's multipart upload; never deletes completed objects. */
    public function abortInterrupted(BackupRun $run): bool
    {
        $uploadId = $run->destination_snapshot['multipart_upload_id'] ?? null;
        if (! is_string($uploadId) || $uploadId === '') {
            return true;
        }
        try {
            [$client, $bucket, $key] = $this->context($run);
            $client->abortMultipartUpload(['Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId]);

            return true;
        } catch (Throwable $failure) {
            return $failure instanceof S3Exception && $failure->getAwsErrorCode() === 'NoSuchUpload';
        }
    }

    /** @return array{S3Client, string, string} */
    private function context(#[\SensitiveParameter] BackupRun $run): array
    {
        $destination = $run->destination()->firstOrFail();
        $snapshot = $run->destination_snapshot;
        foreach (['endpoint', 'bucket', 'prefix'] as $field) {
            if (($snapshot[$field] ?? null) !== $destination->{$field}) {
                throw new BackupFailure('upload_failed');
            }
        }
        BackupWorkspace::assertRelative($run->object_key);
        BackupWorkspace::assertRelative($snapshot['prefix']);

        return [app(R2Storage::class)->disk($destination)->getClient(), $destination->bucket, $snapshot['prefix'].'/'.$run->object_key];
    }
}
