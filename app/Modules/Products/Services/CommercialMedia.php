<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\Models\ProductVariant;
use Illuminate\Support\Facades\Storage;

class CommercialMedia
{
    public function publicUrl(?string $path, string $prefix): string
    {
        if ($path === null || ! str_starts_with($path, $prefix) || str_contains($path, '..') || str_contains($path, '\\') || ! Storage::disk('public')->exists($path)) {
            return asset('images/product-placeholder.svg');
        }

        return Storage::disk('public')->url($path);
    }

    public function variantUrl(?ProductVariant $variant): string
    {
        $image = $variant?->images->where('disk', 'public')->sortBy('sort_order')->sortByDesc('is_primary')->first()
            ?? $variant?->product?->images->whereNull('product_variant_id')->where('disk', 'public')->sortBy('sort_order')->sortByDesc('is_primary')->first();

        return $this->publicUrl($image?->path, 'products/');
    }
}
