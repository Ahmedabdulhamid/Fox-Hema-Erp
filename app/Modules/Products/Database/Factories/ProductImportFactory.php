<?php

namespace App\Modules\Products\Database\Factories;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Products\Models\ProductImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductImport> */
class ProductImportFactory extends Factory
{
    protected $model = ProductImport::class;

    public function definition(): array
    {
        return ['actor_id' => User::factory(), 'global_scope' => true, 'source_hash' => hash('sha256', fake()->uuid()),
            'fingerprint' => hash('sha256', fake()->uuid()), 'status' => 'uploaded', 'expires_at' => now()->addDay()];
    }
}
