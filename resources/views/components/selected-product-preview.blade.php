@if ($variant)
    @include('components.product-line-identity', ['variant' => $variant, 'title' => $variant->product?->name])
    <x-inventory-variant :variant="$variant" />
@endif
