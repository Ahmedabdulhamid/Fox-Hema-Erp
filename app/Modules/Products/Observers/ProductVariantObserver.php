<?php

namespace App\Modules\Products\Observers;

use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\ProductVariantIdentifierGenerator;
use Illuminate\Validation\ValidationException;

class ProductVariantObserver
{
    public function creating(ProductVariant $variant): void
    {
        if ($variant->isUnallocated() && ((int) $variant->unallocated_product_id !== (int) $variant->product_id
            || $variant->size_id !== null || $variant->color_id !== null)) {
            throw ValidationException::withMessages(['variant' => __('The unallocated variant must belong to its product and have no size or color.')]);
        }
        app(ProductVariantIdentifierGenerator::class)->assign($variant);
    }

    public function updating(ProductVariant $variant): void
    {
        if ($variant->isDirty('unallocated_product_id')
            || ($variant->isUnallocated() && $variant->isDirty(['product_id', 'size_id', 'color_id']))) {
            throw ValidationException::withMessages(['variant' => __('Unallocated variant identity cannot be changed.')]);
        }
    }
}
