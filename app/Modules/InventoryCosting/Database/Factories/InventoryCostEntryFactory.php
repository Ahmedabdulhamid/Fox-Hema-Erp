<?php

namespace App\Modules\InventoryCosting\Database\Factories;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryCostEntry> */
class InventoryCostEntryFactory extends Factory
{
    protected $model = InventoryCostEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'method' => 'avco',
            'quantity' => '10.0000',
            'unit_cost' => '5.000000',
            'total_cost' => '50.0000',
            'entry_type' => 'receipt',
            'stock_movement_id' => fn (array $attributes): int => StockMovement::create([
                'variant_id' => ProductVariant::create([
                    'product_id' => Product::create(['name' => fake()->words(3, true)])->getKey(),
                    'sku' => fake()->unique()->uuid(),
                ])->getKey(),
                'location_id' => Location::create(['name' => fake()->city(), 'type' => 'warehouse'])->getKey(),
                'type' => match ($attributes['entry_type']) {
                    'receipt' => 'purchase',
                    'issue' => 'sale',
                    default => 'adjustment',
                },
                'quantity' => $attributes['quantity'],
                'unit_cost' => $attributes['unit_cost'],
            ])->getKey(),
        ];
    }
}
