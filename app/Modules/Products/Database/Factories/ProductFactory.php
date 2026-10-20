<?php

namespace App\Modules\Products\Database\Factories;

use App\Modules\Products\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return ['name' => fake()->words(3, true), 'status' => 'draft', 'is_active' => false];
    }
}
