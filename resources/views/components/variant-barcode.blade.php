@php
    $barcodeSvg = app(\App\Modules\Products\Services\VariantBarcode::class)->svg($variant->barcode);
@endphp
<div dir="ltr" @class(['fox-barcode', 'is-compact' => $compact ?? true])>
    @if($barcodeSvg)
        <div class="fox-barcode-graphic">{!! $barcodeSvg !!}</div>
    @else
        <span>{{ __('Barcode unavailable') }}</span>
    @endif
    <div class="fox-barcode-value">{{ $variant->barcode ?: __('No barcode') }}</div>
</div>
