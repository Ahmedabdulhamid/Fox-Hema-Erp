<?php

namespace App\Modules\BackupRestore\Providers;

use App\Modules\AccessManagement\Models\User;
use App\Modules\BackupRestore\Console\CheckBackupReadiness;
use App\Modules\BackupRestore\Console\RecoverBackupAttempts;
use App\Modules\BackupRestore\Console\RunBackupAutomation;
use App\Modules\BackupRestore\Models\BackupRun;
use App\Modules\BackupRestore\Policies\BackupRunPolicy;
use App\Modules\BackupRestore\Services\BackupAccess;
use App\Modules\BackupRestore\Services\CaptureProtection;
use App\Modules\BackupRestore\Services\OnlineCaptureProtection;
use App\Modules\BackupRestore\Services\UnavailableCaptureProtection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class BackupRestoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CaptureProtection::class, fn (): CaptureProtection => config('backup-restore.capture.enabled') === true ? new OnlineCaptureProtection : new UnavailableCaptureProtection);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RecoverBackupAttempts::class, RunBackupAutomation::class, CheckBackupReadiness::class]);
        }
        Gate::before(function (User $user, string $ability): ?bool {
            return str_starts_with($ability, 'backups.') ? app(BackupAccess::class)->allows($user, $ability) : null;
        });
        Gate::policy(BackupRun::class, BackupRunPolicy::class);
        $this->loadViewsFrom(app_path('Modules/BackupRestore/Resources/views'), 'backup-restore');
    }
}
