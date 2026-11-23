<?php

namespace App\Modules\BackupRestore\Database\Factories;

use App\Modules\BackupRestore\Models\BackupPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BackupPolicy> */
class BackupPolicyFactory extends Factory
{
    protected $model = BackupPolicy::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['id' => 1, 'enabled' => false, 'timezone' => 'Africa/Cairo', 'daily_time' => '02:00:00', 'retain_daily' => 14, 'retain_weekly' => 4, 'retain_monthly' => 3, 'minimum_verified' => 3];
    }
}
