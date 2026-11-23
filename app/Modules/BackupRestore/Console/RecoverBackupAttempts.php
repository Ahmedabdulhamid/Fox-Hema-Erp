<?php

namespace App\Modules\BackupRestore\Console;

use App\Modules\AccessManagement\Models\User;
use App\Modules\BackupRestore\Services\BackupCoordinator;
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\DatabaseDumper;
use Illuminate\Console\Command;
use Throwable;

class RecoverBackupAttempts extends Command
{
    protected $signature = 'backups:recover {actor : Existing authorized user UUID} {--limit=10 : Maximum workspaces and queued attempts to inspect}';

    protected $description = 'Explicitly recover interrupted backup attempts without retrying jobs or deleting completed R2 objects';

    public function handle(BackupCoordinator $coordinator): int
    {
        try {
            app(DatabaseDumper::class)->connection();
            $actor = User::query()->where('uuid', $this->argument('actor'))->firstOrFail();
            $limit = $this->option('limit');
            if (! is_string($limit) || ! ctype_digit($limit)) {
                throw new BackupFailure('backup_failed');
            }
            $result = $coordinator->recover($actor, (int) $limit);
            $this->info(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $failure) {
            $code = BackupFailure::sanitized($failure)->getMessage();
            $this->error(__('backup_restore.errors.'.$code));

            return self::FAILURE;
        }
    }
}
