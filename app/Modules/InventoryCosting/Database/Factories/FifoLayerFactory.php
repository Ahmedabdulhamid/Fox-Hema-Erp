<?php

namespace App\Modules\InventoryCosting\Database\Factories;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FifoLayer> */
class FifoLayerFactory extends Factory
{
    protected $model = FifoLayer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'quantity' => '10.0000',
            'unit_cost' => '5.000000',
            'remaining_qty' => fn (array $attributes): string => (string) $attributes['quantity'],
            'received_at' => now(),
            'stock_movement_id' => fn (array $attributes): int => StockMovement::create([
                'variant_id' => ProductVariant::create([
                    'product_id' => Product::create(['name' => fake()->words(3, true)])->getKey(),
                    'sku' => fake()->unique()->uuid(),
                ])->getKey(),
                'location_id' => Location::create(['name' => fake()->city(), 'type' => 'warehouse'])->getKey(),
                'type' => 'purchase',
                'quantity' => $attributes['quantity'],
                'unit_cost' => $attributes['unit_cost'],
            ])->getKey(),
            'variant_id' => fn (array $attributes): int => StockMovement::findOrFail($attributes['stock_movement_id'])->variant_id,
        ];
    }
}
