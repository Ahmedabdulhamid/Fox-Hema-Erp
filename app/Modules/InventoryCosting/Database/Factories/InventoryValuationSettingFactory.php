<?php

namespace App\Modules\InventoryCosting\Database\Factories;

use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryValuationSetting> */
class InventoryValuationSettingFactory extends Factory
{
    protected $model = InventoryValuationSetting::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'category_id' => fn (): int => Category::create(['name' => fake()->words(3, true), 'code' => fake()->unique()->uuid()])->getKey(),
            'costing_method' => 'avco',
            'note' => null,
        ];
    }
}
