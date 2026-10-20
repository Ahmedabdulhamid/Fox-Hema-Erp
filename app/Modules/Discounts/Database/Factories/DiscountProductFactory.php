<?php

namespace App\Modules\Discounts\Database\Factories;

use App\Modules\Discounts\Models\Discount;
use App\Modules\Discounts\Models\DiscountProduct;
use App\Modules\Products\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DiscountProduct> */
class DiscountProductFactory extends Factory
{
    protected $model = DiscountProduct::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'discount_id' => Discount::factory(),
            'product_id' => fn (): int => Product::create(['name' => fake()->words(3, true)])->getKey(),
        ];
    }
}
