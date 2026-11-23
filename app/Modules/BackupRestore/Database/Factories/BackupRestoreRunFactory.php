<?php

namespace App\Modules\BackupRestore\Database\Factories;

use App\Modules\BackupRestore\Models\BackupRestoreRun;
use App\Modules\BackupRestore\Models\BackupRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BackupRestoreRun> */
class BackupRestoreRunFactory extends Factory
{
    protected $model = BackupRestoreRun::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['backup_run_id' => BackupRun::factory(), 'mode' => 'verify_only', 'target_identifier' => 'isolated-test-target', 'status' => 'requested'];
    }
}
