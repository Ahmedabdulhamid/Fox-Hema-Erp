<?php

namespace App\Modules\Products\Database\Factories;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductVariant> */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return ['product_id' => Product::factory(), 'sku' => fake()->unique()->uuid(), 'barcode' => null, 'size_id' => null, 'color_id' => null, 'sale_price' => '0.0000', 'cost_price' => null, 'is_active' => true];
    }
}
