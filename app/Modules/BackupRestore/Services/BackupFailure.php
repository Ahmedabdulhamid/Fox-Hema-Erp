<?php

namespace App\Modules\BackupRestore\Services;

use RuntimeException;
use Throwable;

final class BackupFailure extends RuntimeException
{
    public const COVERAGE_CODES = ['restore_disabled', 'restore_schema_missing', 'restore_identity_mismatch', 'restore_sql_unsupported', 'restore_target_unsafe', 'restore_failed', 'restore_interrupted', 'restore_busy', 'restore_verification_failed', 'restore_cleanup_unverified', 'restore_limit_exceeded', 'automation_disabled', 'automation_schema_missing', 'schedule_invalid', 'schedule_missed',
        'retention_ineligible', 'retention_failed', 'retention_identity_mismatch', 'retention_delete_unverified',
        'capture_cleanup_unverified', 'runtime_coverage_unverified', 'worker_supervision_unverified', 'watchdog_supervision_unverified',
        'backup_queue_isolation_unverified', 'orphan_writer_recovery_unverified', 'write_coordination_tables_missing'];

    public const CODES = [...self::COVERAGE_CODES, 'capture_disabled', 'capture_accounts_unapproved', 'capture_instance_unapproved', 'capture_protection_unavailable', 'write_paths_unverified', 'external_writers_unverified', 'write_admission_disabled', 'write_coordination_unavailable', 'write_barrier_active', 'write_barrier_recovery_required', 'write_barrier_fenced', 'write_drain_timeout', 'write_capture_expired', 'write_transactions_open', 'write_admission_required', 'write_worker_unverified', 'capture_watchdog_unavailable', 'r2_not_configured', 'key_unavailable', 'key_not_independent', 'private_storage_unavailable', 'dump_binary_unavailable', 'dump_incompatible', 'database_identity_mismatch', 'dump_failed', 'dump_incomplete', 'unsupported_storage', 'unsafe_path', 'missing_media', 'private_files_unclassified', 'files_changed', 'archive_invalid', 'authentication_failed', 'upload_failed', 'readback_failed', 'checksum_mismatch', 'lease_busy', 'lease_lost', 'lease_expired', 'worker_interrupted', 'queue_unavailable', 'queue_dispatch_failed', 'release_unconfigured', 'permission_revoked', 'backup_failed'];

    /** @param array<string, list<int>> $evidence */
    public function __construct(string $reason, private readonly array $evidence = [])
    {
        parent::__construct(in_array($reason, self::CODES, true) ? $reason : 'backup_failed');
    }

    public static function sanitized(Throwable $failure, string $fallback = 'backup_failed'): self
    {
        return new self($failure instanceof self ? $failure->getMessage() : $fallback, $failure instanceof self ? $failure->diagnostics() : []);
    }

    /** @return array<string, list<int>> */
    public function diagnostics(): array
    {
        $safe = [];
        foreach (['product_image_ids', 'brand_ids'] as $key) {
            $safe[$key] = array_values(array_slice(array_filter($this->evidence[$key] ?? [], fn ($id): bool => is_int($id) && $id > 0), 0, 100));
        }

        return $safe;
    }
}
