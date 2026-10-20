<?php

namespace App\Modules\Sales\Database\Factories;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesOrderItem> */
class SalesOrderItemFactory extends Factory
{
    protected $model = SalesOrderItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sales_order_id' => SalesOrder::factory(),
            'variant_id' => fn (): int => ProductVariant::create([
                'product_id' => Product::create(['name' => fake()->words(3, true)])->getKey(),
                'sku' => fake()->unique()->uuid(),
            ])->getKey(),
            'description' => null,
            'quantity' => '10.0000',
            'unit_price' => '5.000000',
            'subtotal' => '50.0000',
            'discount_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'line_total' => '50.0000',
            'notes' => null,
        ];
    }
}
