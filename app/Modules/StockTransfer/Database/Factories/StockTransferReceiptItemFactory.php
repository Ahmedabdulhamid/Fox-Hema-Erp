<?php

namespace App\Modules\StockTransfer\Database\Factories;

use App\Modules\StockTransfer\Models\StockTransferReceipt;
use App\Modules\StockTransfer\Models\StockTransferReceiptItem;
use App\Modules\StockTransfer\Models\StockTransferShipmentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockTransferReceiptItem> */
class StockTransferReceiptItemFactory extends Factory
{
    protected $model = StockTransferReceiptItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'stock_transfer_shipment_item_id' => StockTransferShipmentItem::factory(),
            'stock_transfer_shipment_id' => fn (array $attributes): int => StockTransferShipmentItem::findOrFail($attributes['stock_transfer_shipment_item_id'])->stock_transfer_shipment_id,
            'stock_transfer_receipt_id' => fn (array $attributes): int => StockTransferReceipt::factory()->create([
                'stock_transfer_shipment_id' => $attributes['stock_transfer_shipment_id'],
            ])->getKey(),
            'received_quantity' => '10.0000',
            'damaged_quantity' => '0.0000',
            'notes' => null,
        ];
    }
}
