@php
    $imageUrl = app(\App\Modules\Products\Services\CommercialMedia::class)->variantUrl($variant);
@endphp
<div class="fox-commercial-product">
    <img src="{{ $imageUrl }}" alt="{{ $variant?->product?->name ?? __('Product') }}" width="40" height="40" loading="lazy">
    <div>
            @if ($variant?->product?->brand)
                @include('components.commercial-brand-preview', ['brand' => $variant->product->brand, 'imageUrl' => app(\App\Modules\Products\Services\CommercialMedia::class)->publicUrl($variant->product->brand->icon, 'brands/icons/')])
            @endif
        <strong>{{ $title ?? $variant?->displayName() ?? __('Choose a product') }}</strong>
        @if ($variant)<x-inventory-variant :variant="$variant" />@endif
        <small><bdi>{{ $variant?->sku ?? '—' }}</bdi></small>
    </div>
</div>
