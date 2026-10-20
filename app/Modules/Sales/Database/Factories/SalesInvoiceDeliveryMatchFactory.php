<?php

namespace App\Modules\Sales\Database\Factories;

use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceDeliveryMatch;
use App\Modules\Sales\Models\SalesInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesInvoiceDeliveryMatch> */
class SalesInvoiceDeliveryMatchFactory extends Factory
{
    protected $model = SalesInvoiceDeliveryMatch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sales_delivery_item_id' => SalesDeliveryItem::factory(),
            'sales_invoice_item_id' => function (array $attributes): int {
                $deliveryItem = SalesDeliveryItem::findOrFail($attributes['sales_delivery_item_id']);
                $invoice = SalesInvoice::factory()->create([
                    'sales_order_id' => $deliveryItem->sales_order_id,
                    'location_id' => $deliveryItem->salesDelivery->location_id,
                ]);

                return SalesInvoiceItem::factory()->create([
                    'sales_invoice_id' => $invoice->id,
                    'sales_order_item_id' => $deliveryItem->sales_order_item_id,
                    'variant_id' => $deliveryItem->salesOrderItem->variant_id,
                ])->getKey();
            },
            'matched_quantity' => '10.0000',
        ];
    }
}
