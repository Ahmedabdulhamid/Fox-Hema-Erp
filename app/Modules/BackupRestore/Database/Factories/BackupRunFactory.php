<?php

namespace App\Modules\BackupRestore\Database\Factories;

use App\Modules\BackupRestore\Models\BackupDestination;
use App\Modules\BackupRestore\Models\BackupRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BackupRun> */
class BackupRunFactory extends Factory
{
    protected $model = BackupRun::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['destination_id' => BackupDestination::factory(), 'trigger' => 'manual', 'idempotency_key' => (string) Str::uuid(), 'status' => 'queued', 'verification_status' => 'pending'];
    }
}
