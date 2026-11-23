<?php

namespace App\Modules\BackupRestore\Jobs;

use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\IsolatedRestoreCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class VerifyIsolatedRestore implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $restoreUuid)
    {
        $this->timeout = (int) config('backup-restore.restore.timeout') + 60;
    }

    /**
     * Execute the job.
     */
    public function handle(IsolatedRestoreCoordinator $coordinator): void
    {
        try {
            $coordinator->execute($this->restoreUuid);
        } catch (\Throwable $failure) {
            throw BackupFailure::sanitized($failure, 'restore_failed');
        }
    }
}
