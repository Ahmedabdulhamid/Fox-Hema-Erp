<?php

namespace App\Modules\BackupRestore\Services;

use Illuminate\Support\Facades\Schema;

class BackupAutomationState
{
    private const REQUEST_SCHEMA_CACHE_KEY = 'backup_restore.automation.schema_available';

    /** @return list<string> */
    public function blockers(string $operation): array
    {
        return $this->resolveBlockers($operation, false);
    }

    /** @return list<string> */
    public function displayBlockers(string $operation): array
    {
        return $this->resolveBlockers($operation, true);
    }

    /** @return list<string> */
    private function resolveBlockers(string $operation, bool $forDisplay): array
    {
        $setting = match ($operation) {
            'schedule' => 'scheduling_approved', 'retention' => 'retention_deletion_approved',
            default => throw new BackupFailure('automation_disabled'),
        };
        $reasons = config('backup-restore.automation.'.$setting) === true ? [] : ['automation_disabled'];
        if ($operation === 'schedule' && config('backup-restore.automation.controller_enabled') !== true) {
            $reasons[] = 'automation_disabled';
        }
        if (! $this->schemaIsAvailable()) {
            $reasons[] = 'automation_schema_missing';
        }
        if ($operation === 'retention' && config('backup-restore.automation.conditional_delete_verified') !== true) {
            $reasons[] = 'retention_delete_unverified';
        }
        if ($operation === 'retention' && ! is_array(config('backup-restore.automation.approved_deletion_scope'))) {
            $reasons[] = 'retention_identity_mismatch';
        }

        $readiness = app(BackupReadiness::class);
        $readinessBlockers = $forDisplay ? $readiness->displayBlockers() : $readiness->blockers();

        return array_values(array_unique([...$reasons, ...$readinessBlockers]));
    }

    private function schemaIsAvailable(): bool
    {
        $request = request();
        if ($request->hasSession() && $request->attributes->has(self::REQUEST_SCHEMA_CACHE_KEY)) {
            return $request->attributes->get(self::REQUEST_SCHEMA_CACHE_KEY) === true;
        }

        $available = $this->hasTable('backup_schedule_occurrences') && $this->hasTable('backup_deletions')
            && $this->hasTable('backup_alerts') && $this->hasTable('backup_alert_deliveries')
            && Schema::hasColumns('backup_policies', ['schedule_requested', 'schedule_requested_at', 'retention_requested', 'maximum_backups', 'retention_days']);
        if ($request->hasSession()) {
            $request->attributes->set(self::REQUEST_SCHEMA_CACHE_KEY, $available);
        }

        return $available;
    }

    public function hasAlertsTable(): bool
    {
        return $this->hasTable('backup_alerts');
    }

    private function hasTable(string $table): bool
    {
        $request = request();
        if (! $request->hasSession()) {
            return Schema::hasTable($table);
        }

        $cacheKey = self::REQUEST_SCHEMA_CACHE_KEY.'.'.$table;
        if ($request->attributes->has($cacheKey)) {
            return $request->attributes->get($cacheKey) === true;
        }

        $exists = Schema::hasTable($table);
        $request->attributes->set($cacheKey, $exists);

        return $exists;
    }

    public function assertReady(string $operation): void
    {
        $blockers = $this->blockers($operation);
        if ($blockers !== []) {
            throw new BackupFailure($blockers[0]);
        }
    }
}
