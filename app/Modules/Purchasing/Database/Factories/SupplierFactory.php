<?php

namespace App\Modules\Purchasing\Database\Factories;

use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Supplier> */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'contact' => fake()->name(),
            'phone' => fake()->numerify('###########'),
            'is_active' => true,
            'code' => fake()->unique()->uuid(),
            'email' => fake()->safeEmail(),
            'address' => null,
            'tax_number' => null,
            'payment_terms_days' => 0,
            'notes' => null,
        ];
    }
}
