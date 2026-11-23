<?php

namespace App\Modules\BackupRestore\Jobs;

use App\Modules\BackupRestore\Services\BackupCoordinator;
use App\Modules\BackupRestore\Services\BackupFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CreateFullBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $runUuid)
    {
        $this->timeout = (int) config('backup-restore.job_timeout');
    }

    public function handle(BackupCoordinator $coordinator): void
    {
        try {
            $coordinator->execute($this->runUuid);
        } catch (Throwable) {
            throw new BackupFailure('backup_failed');
        }
    }
}
