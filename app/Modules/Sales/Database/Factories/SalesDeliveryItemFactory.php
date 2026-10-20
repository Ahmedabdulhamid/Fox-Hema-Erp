<?php

namespace App\Modules\Sales\Database\Factories;

use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesDeliveryItem> */
class SalesDeliveryItemFactory extends Factory
{
    protected $model = SalesDeliveryItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sales_order_item_id' => SalesOrderItem::factory(),
            'sales_order_id' => fn (array $attributes): int => SalesOrderItem::findOrFail($attributes['sales_order_item_id'])->sales_order_id,
            'sales_delivery_id' => fn (array $attributes): int => SalesDelivery::factory()->create([
                'sales_order_id' => $attributes['sales_order_id'],
            ])->getKey(),
            'quantity' => '10.0000',
            'stock_movement_id' => null,
        ];
    }
}
