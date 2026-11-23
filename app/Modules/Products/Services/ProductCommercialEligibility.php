<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\Enums\ProductStatus;
use App\Modules\Products\Models\Product;

class ProductCommercialEligibility
{
    public function refresh(Product $product): void
    {
        $isEligible = $product->status === ProductStatus::Active
            && $product->variants()->where('is_active', true)->whereRaw("TRIM(sku) <> ''")->where('sale_price', '>=', 0)->exists();

        if ($product->is_active !== $isEligible) {
            $product->is_active = $isEligible;
            $product->save();
        }
    }
}
