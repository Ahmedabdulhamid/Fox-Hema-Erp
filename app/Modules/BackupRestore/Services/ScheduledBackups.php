<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\BackupRestore\Models\BackupPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ScheduledBackups
{
    public function tick(?CarbonImmutable $at = null): string
    {
        $at ??= CarbonImmutable::now('UTC');
        $occurrenceId = null;
        try {
            $reservation = DB::transaction(function () use ($at, &$occurrenceId): array|string {
                DB::table('modules')->where('code', 'backup_restore')->lockForUpdate()->firstOrFail();
                $policy = BackupPolicy::query()->lockForUpdate()->find(1);
                if (! $policy?->schedule_requested) {
                    return 'disabled';
                }
                $schedule = app(BackupSchedule::class);
                $due = $schedule->occurrence($policy, $at);
                if ($policy->schedule_requested_at?->gt($due)) {
                    return 'not_due';
                }
                $civilDate = $due->setTimezone($policy->timezone)->format('Y-m-d');
                DB::table('backup_schedule_occurrences')->insertOrIgnore(['policy_id' => $policy->id,
                    'civil_date' => $civilDate, 'scheduled_for' => $due->format('Y-m-d H:i:s'),
                    'policy_version' => $policy->configuration_version, 'status' => 'pending', 'created_at' => $at, 'updated_at' => $at]);
                $occurrence = DB::table('backup_schedule_occurrences')->where('policy_id', $policy->id)->where('civil_date', $civilDate)->lockForUpdate()->first();
                $occurrenceId = $occurrence->id;
                $policy->update(['next_due_at' => $schedule->occurrence($policy, $at, true)]);
                if (in_array($occurrence->status, ['dispatched', 'missed', 'dispatch_failed'], true)) {
                    return $occurrence->status;
                }
                if ($occurrence->policy_version !== $policy->configuration_version
                    || $at->timestamp - $due->timestamp > (int) config('backup-restore.automation.schedule_grace_minutes', 60) * 60) {
                    DB::table('backup_schedule_occurrences')->where('id', $occurrenceId)->update(['status' => 'missed', 'failure_code' => 'schedule_missed', 'updated_at' => $at]);
                    app(BackupAlerts::class)->record('schedule_missed');

                    return 'missed';
                }

                return ['policy' => $policy, 'due' => $due];
            });
            if (is_string($reservation)) {
                return $reservation;
            }
            app(BackupCoordinator::class)->requestScheduled($reservation['policy'], $reservation['due']);

            return 'dispatched';
        } catch (Throwable $failure) {
            if ($occurrenceId !== null && $failure instanceof BackupFailure && $failure->getMessage() === 'queue_dispatch_failed') {
                DB::table('backup_schedule_occurrences')->where('id', $occurrenceId)->update(['status' => 'dispatch_failed', 'failure_code' => 'queue_dispatch_failed']);
                app(BackupAlerts::class)->record('backup_failed', null, 'queue_dispatch_failed');

                return 'dispatch_failed';
            }
            if ($occurrenceId !== null && $failure instanceof BackupFailure) {
                DB::table('backup_schedule_occurrences')->where('id', $occurrenceId)->whereIn('status', ['pending', 'blocked'])
                    ->update(['status' => 'blocked', 'failure_code' => $failure->getMessage(), 'updated_at' => $at]);
                app(BackupAlerts::class)->record('readiness_unavailable', null, $failure->getMessage());

                return 'blocked';
            }
            throw BackupFailure::sanitized($failure, 'automation_schema_missing');
        }
    }
}
