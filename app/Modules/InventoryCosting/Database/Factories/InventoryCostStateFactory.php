<?php

namespace App\Modules\InventoryCosting\Database\Factories;

use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryCostState> */
class InventoryCostStateFactory extends Factory
{
    protected $model = InventoryCostState::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'variant_id' => fn (): int => ProductVariant::create([
                'product_id' => Product::create(['name' => fake()->words(3, true)])->getKey(),
                'sku' => fake()->unique()->uuid(),
            ])->getKey(),
            'avg_cost' => '0.000000',
            'on_hand_qty' => '0.0000',
            'total_value' => '0.0000',
        ];
    }
}
