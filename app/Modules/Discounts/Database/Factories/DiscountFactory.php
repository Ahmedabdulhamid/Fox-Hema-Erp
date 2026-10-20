<?php

namespace App\Modules\Discounts\Database\Factories;

use App\Modules\Discounts\Models\Discount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Discount> */
class DiscountFactory extends Factory
{
    protected $model = Discount::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'code' => fake()->unique()->uuid(),
            'type' => 'percentage',
            'value' => '10.0000',
            'scope' => 'product',
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
            'is_stackable' => false,
        ];
    }
}
