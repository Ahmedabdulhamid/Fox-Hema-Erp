<?php

namespace App\Modules\BackupRestore\Database\Factories;

use App\Modules\BackupRestore\Models\BackupDestination;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BackupDestination> */
class BackupDestinationFactory extends Factory
{
    protected $model = BackupDestination::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => 'Test R2', 'endpoint' => 'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com', 'bucket' => 'test-private-bucket', 'prefix' => 'test-backups', 'access_key_id' => 'test-only-access', 'secret_access_key' => 'test-only-secret'];
    }
}
