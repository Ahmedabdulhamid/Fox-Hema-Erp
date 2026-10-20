<?php

namespace App\Modules\Purchasing\Database\Factories;

use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\PurchaseReceiptItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseReceiptItem> */
class PurchaseReceiptItemFactory extends Factory
{
    protected $model = PurchaseReceiptItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'purchase_order_item_id' => PurchaseOrderItem::factory(),
            'purchase_receipt_id' => fn (array $attributes): int => PurchaseReceipt::factory()->create([
                'purchase_order_id' => PurchaseOrderItem::findOrFail($attributes['purchase_order_item_id'])->purchase_order_id,
            ])->getKey(),
            'quantity' => '10.0000',
            'unit_cost' => '5.000000',
            'stock_movement_id' => null,
            'purchase_order_id' => fn (array $attributes): int => PurchaseOrderItem::findOrFail($attributes['purchase_order_item_id'])->purchase_order_id,
        ];
    }
}
