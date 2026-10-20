<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

class SellingPriceResolver
{
    public function resolve(ProductVariant $variant): string
    {
        $price = $variant->product->has_fixed_selling_price ? $variant->product->sale_price : $variant->sale_price;
        if ($price === null || BigDecimal::of($price)->isNegative()) {
            throw ValidationException::withMessages(['sale_price' => __('A valid shared selling price is required.')]);
        }

        return (string) BigDecimal::of($price)->toScale(4);
    }
}
