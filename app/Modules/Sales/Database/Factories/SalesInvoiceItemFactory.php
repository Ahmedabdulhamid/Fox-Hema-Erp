<?php

namespace App\Modules\Sales\Database\Factories;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesInvoiceItem> */
class SalesInvoiceItemFactory extends Factory
{
    protected $model = SalesInvoiceItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sales_invoice_id' => SalesInvoice::factory(),
            'sales_order_item_id' => null,
            'variant_id' => fn (): int => ProductVariant::create([
                'product_id' => Product::create(['name' => fake()->words(3, true)])->getKey(),
                'sku' => fake()->unique()->uuid(),
            ])->getKey(),
            'description' => null,
            'line_type' => 'product',
            'quantity' => '10.0000',
            'unit_price' => '5.000000',
            'subtotal' => '50.0000',
            'discount_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'line_total' => '50.0000',
        ];
    }
}
