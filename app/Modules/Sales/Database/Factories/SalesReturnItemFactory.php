<?php

namespace App\Modules\Sales\Database\Factories;

use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesReturnItem> */
class SalesReturnItemFactory extends Factory
{
    protected $model = SalesReturnItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sales_delivery_item_id' => SalesDeliveryItem::factory(),
            'sales_return_id' => fn (array $attributes): int => SalesReturn::factory()->create([
                'sales_order_id' => SalesDeliveryItem::findOrFail($attributes['sales_delivery_item_id'])->sales_order_id,
                'location_id' => SalesDeliveryItem::findOrFail($attributes['sales_delivery_item_id'])->salesDelivery->location_id,
            ])->getKey(),
            'sales_invoice_item_id' => null,
            'variant_id' => fn (array $attributes): int => SalesDeliveryItem::findOrFail($attributes['sales_delivery_item_id'])->salesOrderItem->variant_id,
            'quantity' => '10.0000',
            'unit_price' => '5.000000',
            'refund_amount' => '50.0000',
            'disposition' => 'restock',
            'notes' => null,
        ];
    }
}
