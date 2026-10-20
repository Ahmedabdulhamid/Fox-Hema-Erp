<?php

namespace App\Modules\Purchasing\Database\Factories;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseOrderItem> */
class PurchaseOrderItemFactory extends Factory
{
    protected $model = PurchaseOrderItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'variant_id' => fn (): int => ProductVariant::create([
                'product_id' => Product::create(['name' => fake()->words(3, true)])->getKey(),
                'sku' => fake()->unique()->uuid(),
            ])->getKey(),
            'quantity' => '10.0000',
            'unit_price' => '5.000000',
            'discount_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'line_total' => '50.0000',
            'subtotal' => '50.0000',
            'notes' => null,
        ];
    }
}
