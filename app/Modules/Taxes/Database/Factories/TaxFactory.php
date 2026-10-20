<?php

namespace App\Modules\Taxes\Database\Factories;

use App\Modules\Taxes\Models\Tax;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tax> */
class TaxFactory extends Factory
{
    protected $model = Tax::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'code' => fake()->unique()->uuid(),
            'type' => 'percentage',
            'rate' => '0.0000',
            'price_inclusion' => 'excluded',
            'usage' => 'sales',
            'is_active' => true,
            'effective_from' => null,
            'effective_to' => null,
        ];
    }
}
