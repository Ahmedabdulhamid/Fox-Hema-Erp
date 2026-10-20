<?php

namespace Tests\Concerns;

use App\Modules\Discounts\Models\Discount;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Taxes\Models\Tax;

trait HasConfiguredSalesPricing
{
    private function configureSalesPricing(ProductVariant $variant, string $discount = '3.6585', string $tax = '7.5949'): void
    {
        $variant->product->discounts()->attach(Discount::factory()->create(['value' => $discount]));
        $variant->product->taxes()->attach(Tax::factory()->create(['rate' => $tax, 'usage' => 'sales']));
    }
}
