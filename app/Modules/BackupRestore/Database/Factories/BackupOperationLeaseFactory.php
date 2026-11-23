<?php

namespace App\Modules\BackupRestore\Database\Factories;

use App\Modules\BackupRestore\Models\BackupOperationLease;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BackupOperationLease> */
class BackupOperationLeaseFactory extends Factory
{
    protected $model = BackupOperationLease::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['scope' => 'global', 'operation_uuid' => (string) Str::uuid(), 'operation_type' => 'verification', 'owner_token' => Str::random(40), 'fencing_number' => 1, 'heartbeat_at' => now(), 'expires_at' => now()->addMinutes(5)];
    }
}
