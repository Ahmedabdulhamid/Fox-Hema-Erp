<?php

namespace App\Modules\Products\Database\Factories;

use App\Modules\Products\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return ['name' => fake()->words(2, true), 'code' => fake()->unique()->uuid(), 'parent_id' => null, 'is_active' => true];
    }
}
