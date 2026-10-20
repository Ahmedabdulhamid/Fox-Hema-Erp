<?php

namespace App\Modules\Products\Database\Factories;

use App\Modules\Products\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Brand> */
class BrandFactory extends Factory
{
    protected $model = Brand::class;

    public function definition(): array
    {
        return ['name' => fake()->unique()->company(), 'is_active' => true];
    }
}
