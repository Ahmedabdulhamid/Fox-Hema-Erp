<?php

namespace App\Modules\BackupRestore\Console;

use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\LocalRecoveryReadiness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backups:readiness')]
#[Description('Inspect local backup and recovery prerequisites without running operations or changing gates')]
class CheckBackupReadiness extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(LocalRecoveryReadiness $readiness): int
    {
        try {
            $report = $readiness->inspect();
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $report['manual_backup_ready'] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $failure) {
            $this->error(BackupFailure::sanitized($failure)->getMessage());

            return self::FAILURE;
        }
    }
}
