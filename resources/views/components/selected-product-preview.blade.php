@if ($variant)
    @include('components.product-line-identity', ['variant' => $variant, 'title' => $variant->product?->name])
@endif
