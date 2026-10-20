<?php

namespace App\Modules\InventoryCosting\Database\Factories;

use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryStandardCost> */
class InventoryStandardCostFactory extends Factory
{
    protected $model = InventoryStandardCost::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'variant_id' => fn (): int => ProductVariant::create([
                'product_id' => Product::create(['name' => fake()->words(3, true)])->getKey(),
                'sku' => fake()->unique()->uuid(),
            ])->getKey(),
            'standard_cost' => '5.000000',
            'updated_by' => null,
        ];
    }
}
