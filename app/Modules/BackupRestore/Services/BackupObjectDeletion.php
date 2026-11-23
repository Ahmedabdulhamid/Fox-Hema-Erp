<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\BackupRestore\Models\BackupRun;
use Aws\S3\Exception\S3Exception;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackupObjectDeletion
{
    /** @return array{bucket: string, key: string, endpoint: string, sha256: string, size: int, attempt: string} */
    public function identity(BackupRun $run): array
    {
        $destination = $run->destination()->firstOrFail();
        $snapshot = $run->destination_snapshot;
        $scope = config('backup-restore.automation.approved_deletion_scope');
        foreach (['endpoint', 'bucket', 'prefix'] as $field) {
            if (! is_array($scope) || ($scope[$field] ?? null) !== $destination->{$field}
                || ($snapshot[$field] ?? null) !== $destination->{$field}) {
                throw new BackupFailure('retention_identity_mismatch');
            }
        }
        $attempt = $snapshot['attempt_uuid'] ?? '';
        if ($destination->bucket !== 'fox-hema-backups' || $destination->status !== 'active'
            || ! R2Storage::validEndpoint($destination->endpoint)
            || ! Str::isUuid($attempt)
            || $run->object_key !== 'runs/'.$run->uuid.'/attempt-'.$attempt.'.fhbk'
            || ($snapshot['database_name'] ?? null) !== app(DatabaseDumper::class)->connection()->getDatabaseName()
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $run->ciphertext_checksum ?? '') || $run->size_bytes < 1) {
            throw new BackupFailure('retention_identity_mismatch');
        }
        BackupWorkspace::assertRelative($destination->prefix);

        return ['bucket' => $destination->bucket, 'key' => $destination->prefix.'/'.$run->object_key,
            'endpoint' => $destination->endpoint, 'sha256' => $run->ciphertext_checksum, 'size' => $run->size_bytes, 'attempt' => $attempt];
    }

    /** Reconcile absence only; never issue a deletion during interrupted-operation recovery. */
    public function isMissing(BackupRun $run): bool
    {
        $identity = $this->identity($run);
        $client = app(R2Storage::class)->disk($run->destination()->firstOrFail())->getClient();
        try {
            $client->headObject(['Bucket' => $identity['bucket'], 'Key' => $identity['key']]);
        } catch (S3Exception $failure) {
            if ($failure->getAwsErrorCode() === 'NoSuchKey') {
                return true;
            }
            throw new BackupFailure('retention_failed');
        }

        return false;
    }

    /** Only the claimed immutable artifact is eligible; persist its ETag before issuing deletion. */
    public function delete(BackupRun $run, ?string $claimedEtag, Closure $rememberEtag): void
    {
        app(BackupAutomationState::class)->assertReady('retention');
        if (! BackupRun::query()->whereKey($run->id)->where('status', 'deleting')->exists()
            || ! DB::table('backup_deletions')->where('backup_run_id', $run->id)->where('status', 'deleting')->exists()) {
            throw new BackupFailure('retention_ineligible');
        }
        if (config('backup-restore.automation.retention_deletion_approved') !== true
            || config('backup-restore.automation.conditional_delete_verified') !== true) {
            throw new BackupFailure('automation_disabled');
        }
        $identity = $this->identity($run);
        $client = app(R2Storage::class)->disk($run->destination()->firstOrFail())->getClient();
        try {
            $head = $client->headObject(['Bucket' => $identity['bucket'], 'Key' => $identity['key']]);
        } catch (S3Exception $failure) {
            if ($claimedEtag !== null && $failure->getAwsErrorCode() === 'NoSuchKey') {
                return;
            }
            throw new BackupFailure('retention_failed');
        }
        $metadata = $head['Metadata'] ?? [];
        $etag = $head['ETag'] ?? null;
        if (($metadata['application'] ?? null) !== 'fox-hema-erp' || ($metadata['run-uuid'] ?? null) !== $run->uuid
            || ($metadata['attempt-uuid'] ?? null) !== $identity['attempt'] || ($metadata['format'] ?? null) !== 'fhbk-v1'
            || ($metadata['sha256'] ?? null) !== $identity['sha256'] || (int) ($head['ContentLength'] ?? -1) !== $identity['size']
            || ! is_string($etag) || $etag === '' || ($claimedEtag !== null && ! hash_equals($claimedEtag, $etag))) {
            throw new BackupFailure('retention_identity_mismatch');
        }
        $rememberEtag($etag);
        $client->deleteObject(['Bucket' => $identity['bucket'], 'Key' => $identity['key'], 'IfMatch' => $etag]);
        try {
            $client->headObject(['Bucket' => $identity['bucket'], 'Key' => $identity['key']]);
        } catch (S3Exception $failure) {
            if ($failure->getAwsErrorCode() === 'NoSuchKey') {
                return;
            }
        }
        throw new BackupFailure('retention_failed');
    }
}
