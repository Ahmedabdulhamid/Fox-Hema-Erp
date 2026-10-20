<details class="fox-line-barcode">
    <summary>{{ __('Barcode preview') }} · <bdi>{{ $value }}</bdi></summary>
    @include('components.variant-barcode', ['variant' => (object) ['barcode' => $value], 'compact' => true])
</details>
