<?php

namespace App\Modules\Sales\Services;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\SellingPriceResolver;
use App\Modules\Taxes\Services\CommercialDefinitions;
use App\Modules\Taxes\Services\CommercialLineCalculator;
use Illuminate\Support\Collection;

class SalesLinePricing
{
    public function discounts(Product $product): Collection
    {
        return app(CommercialDefinitions::class)->eligible('discounts', 'sales')->whereHas('products', fn ($query) => $query->whereKey($product->id))->orderBy('id')->get();
    }

    public function taxes(Product $product): Collection
    {
        return app(CommercialDefinitions::class)->taxes($product, 'sales', []);
    }

    public function line(ProductVariant $variant, array $input, string $prefix = ''): array
    {
        $input['unit_price'] ??= app(SellingPriceResolver::class)->resolve($variant);

        return app(CommercialLineCalculator::class)->line($variant, $input, $prefix, 'sales');
    }
}
