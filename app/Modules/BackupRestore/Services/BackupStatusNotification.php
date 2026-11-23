<?php

namespace App\Modules\BackupRestore\Services;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

class BackupStatusNotification extends Notification
{
    public function __construct(public readonly string $event, public readonly ?string $runUuid, public readonly ?string $failureCode) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $event = in_array($this->event, BackupAlerts::EVENTS, true) ? $this->event : 'backup_failed';
        $message = (new MailMessage)->subject(__('backup_restore.alerts.'.$event))->line(__('backup_restore.alerts.'.$event));
        if (Str::isUuid($this->runUuid ?? '')) {
            $run = BackupRun::query()->where('uuid', $this->runUuid)->first(['uuid', 'trigger', 'created_at']);
            $name = $run ? app(BackupNameFormatter::class)->labels([$run])[$run->uuid] ?? null : null;
            if ($name !== null) {
                $message->line(__('backup_restore.backup_reference', ['name' => $name]));
            }
        }
        if (in_array($this->failureCode, BackupFailure::CODES, true)) {
            $key = 'backup_restore.errors.'.$this->failureCode;
            $message->line(Lang::has($key) ? __($key) : __('backup_restore.errors.backup_failed'));
        }

        return $message->line(__('backup_restore.restore_warning'));
    }
}
