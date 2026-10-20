<?php

namespace App\Modules\Products\Database\Factories;

use App\Modules\Products\Models\Size;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Size> */
class SizeFactory extends Factory
{
    protected $model = Size::class;

    public function definition(): array
    {
        return ['name' => fake()->unique()->uuid(), 'sort_order' => 0, 'is_active' => true];
    }
}
