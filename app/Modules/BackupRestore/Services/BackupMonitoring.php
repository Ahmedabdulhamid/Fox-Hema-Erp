<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\BackupRestore\Models\BackupPolicy;
use App\Modules\BackupRestore\Models\BackupRestoreRun;
use App\Modules\BackupRestore\Models\BackupRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BackupMonitoring
{
    public function hasActiveOperations(): bool
    {
        return BackupRun::query()
            ->whereIn('status', ['queued', 'capturing', 'encrypting', 'uploading', 'verifying', 'deleting'])->exists()
            || BackupRestoreRun::query()->whereIn('status', ['requested', 'verifying', 'approved', 'restoring'])->exists();
    }

    /** @return array<string, mixed> Read-only; viewing the dashboard cannot dispatch or notify. */
    public function snapshot(): array
    {
        $policy = BackupPolicy::query()->find(1);
        $recentStatuses = DB::table('backup_runs')->whereIn('status', ['available', 'failed'])->orderByDesc('id')->limit(100)->pluck('status');
        $failures = 0;
        foreach ($recentStatuses as $status) {
            if ($status !== 'failed') {
                break;
            }
            $failures++;
        }
        $automationState = app(BackupAutomationState::class);
        $scheduleBlockers = $automationState->displayBlockers('schedule');
        $retentionBlockers = $automationState->displayBlockers('retention');
        $retentionPreview = app(RetentionService::class)->previewForPolicy($policy, false);
        $retentionPreview['effective'] = config('backup-restore.automation.controller_enabled') === true
            && (bool) $policy?->retention_requested && $retentionBlockers === [];

        return ['last_verified' => BackupRun::query()->where('status', 'available')->whereNotNull('verified_at')
            ->whereIn('verification_status', ['checksum_verified', 'restore_verified'])->latest('verified_at')->first(['uuid', 'trigger', 'created_at', 'verified_at', 'size_bytes']),
            'last_failed' => BackupRun::query()->where('status', 'failed')->latest('completed_at')->first(['uuid', 'trigger', 'created_at', 'failure_code', 'completed_at']), 'consecutive_failures' => $failures,
            'next_scheduled' => $policy?->schedule_requested ? app(BackupSchedule::class)->occurrence($policy, CarbonImmutable::now('UTC'), true) : null,
            'scheduling_effective' => (bool) $policy?->schedule_requested && $scheduleBlockers === [],
            'retention_effective' => config('backup-restore.automation.controller_enabled') === true && (bool) $policy?->retention_requested && $retentionBlockers === [],
            'schedule_blockers' => $scheduleBlockers, 'retention_blockers' => $retentionBlockers,
            'retention_preview' => $retentionPreview,
            'alerts' => app(BackupAutomationState::class)->hasAlertsTable()
                ? DB::table('backup_alerts')->latest('id')->limit(20)->get(['event', 'run_uuid', 'failure_code', 'occurrences', 'last_seen_at'])->all() : []];
    }

    public function inspect(): void
    {
        $snapshot = $this->snapshot();
        if ($snapshot['schedule_blockers'] !== []) {
            app(BackupAlerts::class)->record('readiness_unavailable', null, $snapshot['schedule_blockers'][0]);
        }
        if (in_array('r2_not_configured', $snapshot['schedule_blockers'], true)) {
            app(BackupAlerts::class)->record('storage_failed', null, 'r2_not_configured');
        }
        if ($snapshot['consecutive_failures'] >= 3) {
            app(BackupAlerts::class)->record('consecutive_failures');
        }
        if (BackupRun::query()->where('trigger', 'scheduled')->where('status', 'queued')
            ->where('created_at', '<=', now()->subSeconds((int) config('backup-restore.queue_stale_seconds')))->exists()) {
            app(BackupAlerts::class)->record('schedule_missed', null, 'queue_dispatch_failed');
        }
        foreach (BackupRun::query()->whereIn('status', ['available', 'failed'])->where('completed_at', '>=', now()->subDay())->latest('id')->limit(20)->get() as $run) {
            app(BackupAlerts::class)->forRun($run);
        }
    }
}
