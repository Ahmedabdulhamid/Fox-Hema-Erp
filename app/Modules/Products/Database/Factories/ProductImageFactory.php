<?php

namespace App\Modules\Products\Database\Factories;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductImage> */
class ProductImageFactory extends Factory
{
    protected $model = ProductImage::class;

    public function definition(): array
    {
        return ['product_id' => Product::factory(), 'product_variant_id' => null, 'disk' => 'public', 'path' => 'products/'.fake()->uuid().'.jpg', 'is_primary' => false, 'sort_order' => 0];
    }
}
