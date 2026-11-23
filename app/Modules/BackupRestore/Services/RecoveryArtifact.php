<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\BackupRestore\Models\BackupRun;

final class RecoveryArtifact
{
    /** @param array<string, mixed> $identity */
    public function __construct(public readonly array $identity)
    {
        foreach (['run_uuid', 'attempt_uuid'] as $field) {
            if (! is_string($identity[$field] ?? null) || ! preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $identity[$field])) {
                throw new BackupFailure('restore_identity_mismatch');
            }
        }
        foreach (['ciphertext_checksum', 'manifest_checksum', 'schema_checksum'] as $field) {
            if (! is_string($identity[$field] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $identity[$field])) {
                throw new BackupFailure('restore_identity_mismatch');
            }
        }
        foreach (['size_bytes', 'file_count', 'fence'] as $field) {
            if (! is_int($identity[$field] ?? null) || $identity[$field] < 1) {
                throw new BackupFailure('restore_identity_mismatch');
            }
        }
        if ($identity['size_bytes'] > (int) config('backup-restore.restore.max_bytes') || $identity['file_count'] > (int) config('backup-restore.max_files') + 1
            || ! preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D', $identity['key_id'] ?? '') || ! is_string($identity['release_id'] ?? null) || $identity['release_id'] === ''
            || (($identity['source_database'] ?? null) !== 'fox_hema_pos' && ! (app()->environment('testing') && str_starts_with($identity['source_database'] ?? '', 'codex_public_ids_test_')))) {
            throw new BackupFailure('restore_identity_mismatch');
        }
    }

    public static function fromRun(BackupRun $run): self
    {
        if ($run->status !== 'available' || ! in_array($run->verification_status, ['checksum_verified', 'restore_verified'], true)) {
            throw new BackupFailure('restore_identity_mismatch');
        }
        $snapshot = $run->destination_snapshot;
        $destination = $run->destination()->firstOrFail();
        foreach (['endpoint', 'bucket', 'prefix'] as $field) {
            if (($snapshot[$field] ?? null) !== $destination->$field) {
                throw new BackupFailure('restore_identity_mismatch');
            }
        }
        BackupWorkspace::assertRelative($run->object_key);
        BackupWorkspace::assertRelative($destination->prefix);
        if (! str_contains('/'.$run->object_key.'/', '/'.$run->uuid.'/')) {
            throw new BackupFailure('restore_identity_mismatch');
        }

        return new self(['run_uuid' => $run->uuid, 'attempt_uuid' => $snapshot['attempt_uuid'] ?? null, 'fence' => $snapshot['fence'] ?? null,
            'key_id' => $run->encryption_key_id, 'release_id' => $run->application_version, 'source_database' => $snapshot['database_name'] ?? null,
            'ciphertext_checksum' => $run->ciphertext_checksum, 'manifest_checksum' => $run->manifest_checksum,
            'schema_checksum' => $run->schema_checksum, 'size_bytes' => (int) $run->size_bytes, 'file_count' => (int) $run->file_count]);
    }

    /** @param array<string, mixed> $header
     * @param  array<string, mixed>  $verified
     */
    public function authenticate(array $header, array $verified): void
    {
        $manifest = $verified['manifest'];
        foreach (['run_uuid', 'attempt_uuid', 'fence'] as $field) {
            if (($header[$field] ?? null) !== $this->identity[$field] || ($manifest[$field] ?? null) !== $this->identity[$field]) {
                throw new BackupFailure('restore_identity_mismatch');
            }
        }
        if (($header['key_id'] ?? null) !== $this->identity['key_id'] || ($header['manifest_checksum'] ?? null) !== $this->identity['manifest_checksum']
            || $verified['manifest_checksum'] !== $this->identity['manifest_checksum'] || $manifest['release_id'] !== $this->identity['release_id']
            || $manifest['database']['schema_checksum'] !== $this->identity['schema_checksum'] || ($manifest['database']['database'] ?? null) !== $this->identity['source_database']
            || count($manifest['files']) !== $this->identity['file_count'] || ($manifest['composer_lock_checksum'] ?? null) !== hash_file('sha256', base_path('composer.lock'))
            || $manifest['release_id'] !== config('backup-restore.release_id')) {
            throw new BackupFailure('restore_identity_mismatch');
        }
    }
}
