<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnallocatedVariantService
{
    public function ensure(User $actor, Product $product): ProductVariant
    {
        app(ProductsAccess::class)->authorize($actor, 'products.view');
        app(ProductsAccess::class)->authorize($actor, 'products.variants.create');

        return DB::transaction(function () use ($product): ProductVariant {
            ModuleRegistryLock::acquire();
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $existing = $product->variants()->where('unallocated_product_id', $product->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($product->variants()->whereNull('size_id')->whereNull('color_id')->exists()) {
                throw ValidationException::withMessages(['product' => __('An existing simple variant cannot be reinterpreted as unallocated stock. Use a product with explicit size variants.')]);
            }
            $variant = new ProductVariant(['product_id' => $product->id]);
            $variant->unallocated_product_id = $product->id;
            $variant->save();

            return $variant;
        }, 3);
    }
}
