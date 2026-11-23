<?php

namespace App\Modules\BackupRestore\Console;

use App\Modules\BackupRestore\Models\BackupPolicy;
use App\Modules\BackupRestore\Services\BackupAlerts;
use App\Modules\BackupRestore\Services\BackupAutomationState;
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\BackupMonitoring;
use App\Modules\BackupRestore\Services\DatabaseDumper;
use App\Modules\BackupRestore\Services\RetentionService;
use App\Modules\BackupRestore\Services\ScheduledBackups;
use Illuminate\Console\Command;
use Throwable;

class RunBackupAutomation extends Command
{
    protected $signature = 'backups:automation';

    protected $description = 'Evaluate explicitly approved backup automation and administrative alerts';

    public function handle(): int
    {
        if (config('backup-restore.automation.controller_enabled') !== true) {
            $this->info('automation_disabled');

            return self::SUCCESS;
        }
        try {
            app(DatabaseDumper::class)->connection();
            $this->info(app(ScheduledBackups::class)->tick());
            app(BackupMonitoring::class)->inspect();
            if (BackupPolicy::query()->find(1)?->retention_requested && app(BackupAutomationState::class)->blockers('retention') === []) {
                app(RetentionService::class)->prune();
            }
            app(BackupAlerts::class)->deliver();

            return self::SUCCESS;
        } catch (Throwable $failure) {
            $safe = BackupFailure::sanitized($failure);
            app(BackupAlerts::class)->record('readiness_unavailable', null, $safe->getMessage());
            $this->error($safe->getMessage());

            return self::FAILURE;
        }
    }
}
