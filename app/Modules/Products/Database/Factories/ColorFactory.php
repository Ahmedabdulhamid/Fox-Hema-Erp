<?php

namespace App\Modules\Products\Database\Factories;

use App\Modules\Products\Models\Color;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Color> */
class ColorFactory extends Factory
{
    protected $model = Color::class;

    public function definition(): array
    {
        return ['name' => fake()->unique()->colorName(), 'hex_code' => fake()->hexColor(), 'sort_order' => 0, 'is_active' => true];
    }
}
