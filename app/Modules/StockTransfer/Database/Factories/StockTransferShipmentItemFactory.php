<?php

namespace App\Modules\StockTransfer\Database\Factories;

use App\Modules\StockTransfer\Models\StockTransferItem;
use App\Modules\StockTransfer\Models\StockTransferShipment;
use App\Modules\StockTransfer\Models\StockTransferShipmentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockTransferShipmentItem> */
class StockTransferShipmentItemFactory extends Factory
{
    protected $model = StockTransferShipmentItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'stock_transfer_item_id' => StockTransferItem::factory(),
            'stock_transfer_id' => fn (array $attributes): int => StockTransferItem::findOrFail($attributes['stock_transfer_item_id'])->stock_transfer_id,
            'stock_transfer_shipment_id' => fn (array $attributes): int => StockTransferShipment::factory()->create([
                'stock_transfer_id' => $attributes['stock_transfer_id'],
            ])->getKey(),
            'shipped_quantity' => '10.0000',
        ];
    }
}
