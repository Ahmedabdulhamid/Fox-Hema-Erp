<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\BackupRestore\Models\BackupPolicy;
use App\Modules\BackupRestore\Models\BackupRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class BackupAlerts
{
    public const EVENTS = ['backup_verified', 'backup_failed', 'consecutive_failures', 'schedule_missed',
        'storage_failed', 'verification_failed', 'retention_failed', 'readiness_unavailable'];

    public function record(string $event, ?string $runUuid = null, ?string $code = null): void
    {
        if (! in_array($event, self::EVENTS, true)) {
            return;
        }
        try {
            if (! Schema::hasTable('backup_alerts')) {
                return;
            }
            $runUuid = Str::isUuid($runUuid ?? '') ? $runUuid : null;
            $key = hash('sha256', $event.':'.($event === 'backup_verified' ? $runUuid : now('UTC')->format('Y-m-d-H')));
            DB::table('backup_alerts')->insertOrIgnore(['deduplication_key' => $key, 'event' => $event, 'run_uuid' => $runUuid,
                'failure_code' => in_array($code, BackupFailure::CODES, true) ? $code : null,
                'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'occurrences' => 0]);
            DB::table('backup_alerts')->where('deduplication_key', $key)->increment('occurrences', 1, ['last_seen_at' => now(), 'updated_at' => now()]);
        } catch (Throwable) {
            /** A notification storage failure must not change financial/backup outcome. */
        }
    }

    public function forRun(BackupRun $run): void
    {
        if ($run->status === 'available' && $run->verified_at && in_array($run->verification_status, ['checksum_verified', 'restore_verified'], true)) {
            $this->record('backup_verified', $run->uuid);
        } elseif ($run->status === 'failed') {
            $this->record('backup_failed', $run->uuid, $run->failure_code);
            if (in_array($run->failure_code, ['r2_not_configured', 'upload_failed', 'readback_failed'], true)) {
                $this->record('storage_failed', $run->uuid, $run->failure_code);
            }
            if (in_array($run->failure_code, ['checksum_mismatch', 'archive_invalid', 'authentication_failed', 'readback_failed'], true)) {
                $this->record('verification_failed', $run->uuid, $run->failure_code);
            }
        }
    }

    public function deliver(): int
    {
        if (config('backup-restore.automation.notification_delivery_approved') !== true) {
            return 0;
        }
        app(DatabaseDumper::class)->connection();
        $recipients = BackupPolicy::query()->find(1)?->notification_recipients ?? [];
        $sent = 0;
        foreach (DB::table('backup_alerts')->where('created_at', '>=', now()->subDay())->latest('id')->limit(20)->get() as $alert) {
            foreach (array_slice($recipients, 0, 10) as $recipient) {
                if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $hash = hash('sha256', strtolower($recipient));
                $claimed = DB::transaction(function () use ($alert, $hash): bool {
                    DB::table('modules')->where('code', 'backup_restore')->lockForUpdate()->firstOrFail();
                    if (DB::table('backup_alert_deliveries')->where('recipient_hash', $hash)->where('created_at', '>=', now()->subHour())->count() >= 10) {
                        return false;
                    }

                    return DB::table('backup_alert_deliveries')->insertOrIgnore(['alert_id' => $alert->id, 'recipient_hash' => $hash,
                        'status' => 'sending', 'created_at' => now(), 'updated_at' => now()]) === 1;
                });
                if (! $claimed) {
                    continue;
                }
                try {
                    Notification::route('mail', $recipient)->notify(new BackupStatusNotification($alert->event, $alert->run_uuid, $alert->failure_code));
                    DB::table('backup_alert_deliveries')->where('alert_id', $alert->id)->where('recipient_hash', $hash)
                        ->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
                    $sent++;
                } catch (Throwable) {
                    DB::table('backup_alert_deliveries')->where('alert_id', $alert->id)->where('recipient_hash', $hash)
                        ->update(['status' => 'uncertain', 'updated_at' => now()]);
                }
            }
        }

        return $sent;
    }
}
