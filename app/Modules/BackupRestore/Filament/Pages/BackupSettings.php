<?php

namespace App\Modules\BackupRestore\Filament\Pages;

use App\Modules\BackupRestore\Filament\BackupPage;
use App\Modules\BackupRestore\Services\BackupAutomationState;
use App\Modules\BackupRestore\Services\BackupReadiness;
use App\Modules\BackupRestore\Services\BackupSettingsStore;
use App\Modules\BackupRestore\Services\CaptureProtection;
use App\Modules\BackupRestore\Services\OnlineCaptureProtection;
use Filament\Notifications\Notification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class BackupSettings extends BackupPage
{
    protected const TITLE = 'backup_settings';

    protected string $view = 'backup-restore::backup-settings';

    protected static ?string $slug = 'backup-settings';

    /** @var array<string, mixed> */
    public array $settings = [];

    public string $recipients = '';

    #[Locked]
    public int $version = 0;

    public function mount(): void
    {
        $this->loadSettings();
    }

    public function save(): void
    {
        $settings = $this->settings;
        $settings['notification_recipients'] = $this->recipients === '' ? [] : array_map('trim', explode(',', $this->recipients));
        app(BackupSettingsStore::class)->savePolicy($this->actor(), $settings, $this->version);
        $this->loadSettings();
        Notification::make()->success()->title(__('backup_restore.saved'))->send();
    }

    #[Computed]
    public function automationState(): array
    {
        $this->actor();

        return ['controller_enabled' => config('backup-restore.automation.controller_enabled') === true,
            'schedule' => app(BackupAutomationState::class)->blockers('schedule'),
            'retention' => app(BackupAutomationState::class)->blockers('retention'),
            'notification_delivery_approved' => config('backup-restore.automation.notification_delivery_approved') === true];
    }

    #[Computed]
    public function capturePrerequisites(): array
    {
        $this->actor();
        $protection = app(CaptureProtection::class);
        $captureBlockers = app(BackupReadiness::class)->captureBlockers();
        $onlineBlockers = $protection instanceof OnlineCaptureProtection
            ? $captureBlockers
            : app(OnlineCaptureProtection::class)->blockers();

        return array_values(array_unique([
            ...$onlineBlockers,
            ...app(BackupReadiness::class)->blockers(),
        ]));
    }

    private function loadSettings(): void
    {
        $data = app(BackupSettingsStore::class)->policy($this->actor());
        $this->version = $data['configuration_version'];
        $this->recipients = implode(', ', $data['notification_recipients'] ?? []);
        $this->settings = array_intersect_key($data, array_flip(['timezone', 'daily_time', 'retain_daily', 'retain_weekly', 'retain_monthly', 'minimum_verified',
            'schedule_frequency', 'schedule_weekday', 'schedule_requested', 'retention_requested', 'retention_days', 'maximum_backups']));
        $this->settings['daily_time'] = substr($data['daily_time'], 0, 5);
    }
}
