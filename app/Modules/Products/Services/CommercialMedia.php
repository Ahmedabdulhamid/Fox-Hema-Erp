<?php

namespace App\Modules\Products\Services;

use App\Filament\RequestDisplayData;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Support\Facades\Storage;

class CommercialMedia
{
    public function publicUrl(?string $path, string $prefix): string
    {
        if ($path === null || ! str_starts_with($path, $prefix) || str_contains($path, '..') || str_contains($path, '\\')) {
            return asset('images/product-placeholder.svg');
        }

        $disk = Storage::disk('public');

        return RequestDisplayData::remember('commercial-media:'.json_encode([spl_object_id($disk), $path, $prefix], JSON_THROW_ON_ERROR),
            fn (): string => $disk->exists($path) ? $disk->url($path) : asset('images/product-placeholder.svg'));
    }

    public function variantUrl(?ProductVariant $variant): string
    {
        $image = $variant?->images->where('disk', 'public')->sortBy('sort_order')->sortByDesc('is_primary')->first()
            ?? $variant?->product?->images->whereNull('product_variant_id')->where('disk', 'public')->sortBy('sort_order')->sortByDesc('is_primary')->first();

        return $this->publicUrl($image?->path, 'products/');
    }
}
