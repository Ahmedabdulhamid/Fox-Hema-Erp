<?php

namespace App\Modules\InventoryCosting\Database\Factories;

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\InventoryCosting\Models\FifoLayerConsumption;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FifoLayerConsumption> */
class FifoLayerConsumptionFactory extends Factory
{
    protected $model = FifoLayerConsumption::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cost_entry_id' => InventoryCostEntry::factory()->state([
                'method' => 'fifo', 'entry_type' => 'issue', 'quantity' => '-10.0000', 'total_cost' => '-50.0000',
            ]),
            'fifo_layer_id' => function (array $attributes): int {
                $movement = InventoryCostEntry::findOrFail($attributes['cost_entry_id'])->stockMovement;
                $receiptMovement = StockMovement::create([
                    'variant_id' => $movement->variant_id,
                    'location_id' => $movement->location_id,
                    'type' => 'purchase',
                    'quantity' => '10.0000',
                    'unit_cost' => '5.0000',
                ]);

                return FifoLayerFactory::new()->create(['stock_movement_id' => $receiptMovement->id])->getKey();
            },
            'quantity' => '10.0000',
            'unit_cost' => '5.000000',
            'total_cost' => '50.0000',
        ];
    }
}
