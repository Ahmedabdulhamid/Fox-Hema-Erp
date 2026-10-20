<?php

namespace App\Modules\AccessManagement\Database\Factories;

use App\Modules\AccessManagement\Models\Module;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Module> */
class ModuleFactory extends Factory
{
    protected $model = Module::class;

    public function definition(): array
    {
        return ['code' => fake()->unique()->slug(), 'name' => fake()->words(2, true), 'is_active' => true, 'sort_order' => 0];
    }
}
