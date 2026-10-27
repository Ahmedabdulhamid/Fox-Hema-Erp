@php
    $imageUrl = ($grouped ?? false)
        ? app(\App\Modules\Products\Services\CommercialMedia::class)->publicUrl($variant?->product?->images->whereNull('product_variant_id')->where('disk', 'public')->sortBy('sort_order')->sortByDesc('is_primary')->first()?->path, 'products/')
        : app(\App\Modules\Products\Services\CommercialMedia::class)->variantUrl($variant);
    $displaySku = ($grouped ?? false) ? $variant?->product?->default_sku : $variant?->sku;
@endphp
<div class="fox-inventory-product">
    <img src="{{ $imageUrl }}" alt="" loading="lazy" width="48" height="48">
    <div>
        <strong>{{ $title ?? $variant?->product?->name ?? __('Product') }}</strong>
        <span title="{{ $displaySku }}"><bdi>{{ $displaySku }}</bdi></span>
        @if ($variant?->product?->brand)
            <small>{{ $variant->product->brand->name }}</small>
        @endif
        @if (! ($grouped ?? false) && $variant?->color)
            <x-inventory-color :color="$variant->color" />
        @endif
    </div>
</div>
