@php
    $imageUrl = app(\App\Modules\Products\Services\CommercialMedia::class)->variantUrl($variant);
@endphp
<div>
    <div class="fox-product-identity">
        <img src="{{ $imageUrl }}" alt="{{ $title ?? __('Product') }}" loading="lazy" width="48" height="48">
        <div>
            @if ($variant?->product?->brand)
                @include('components.commercial-brand-preview', ['brand' => $variant->product->brand, 'imageUrl' => app(\App\Modules\Products\Services\CommercialMedia::class)->publicUrl($variant->product->brand->icon, 'brands/icons/')])
            @endif
            <strong>{{ $title ?? $variant?->product?->name ?? __('Product') }}</strong>
            @if ($variant)
                <small><bdi>{{ $variant->sku }}</bdi></small>
            @endif
            @if ($variant?->barcode)
                <details class="fox-line-barcode">
                    <summary>{{ __('Barcode preview') }} · <bdi>{{ $variant->barcode }}</bdi></summary>
                    @include('components.variant-barcode', ['variant' => $variant, 'compact' => true])
                </details>
            @endif
        </div>
    </div>
</div>
