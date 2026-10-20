<?php

namespace App\Modules\Taxes\Database\Factories;

use App\Modules\Products\Models\Product;
use App\Modules\Taxes\Models\ProductTax;
use App\Modules\Taxes\Models\Tax;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductTax> */
class ProductTaxFactory extends Factory
{
    protected $model = ProductTax::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => fn (): int => Product::create(['name' => fake()->words(3, true)])->getKey(),
            'tax_id' => Tax::factory(),
        ];
    }
}
