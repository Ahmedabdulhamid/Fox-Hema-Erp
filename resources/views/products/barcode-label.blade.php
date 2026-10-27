<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Print barcode label') }} — {{ $variant->sku }}</title>
    @php
        preg_match('/width="([0-9.]+)mm"/', $svg ?? '', $dimensions);
        $labelWidth = max(50, (float) ($dimensions[1] ?? 50));
        $paperWidth = $labelWidth + 18;
        $textLength = mb_strlen($variant->product->name.' '.$variant->size?->name.' '.$variant->color?->name.' '.$variant->sku.' '.$variant->barcode) + 40;
        $paperHeight = max(65, 50 + ceil($textLength / max(12, floor($labelWidth / 3))) * 4);
    @endphp
    <style>
        body { background:white; color:black; font:12px Arial,sans-serif; margin:8mm; }
        .label { text-align:center; width:{{ $labelWidth }}mm; padding:3mm; border:1px solid #ddd; overflow-wrap:anywhere; }
        .label svg { display:block; margin:2mm auto; max-width:none; }
        .identifier { font-family:monospace; direction:ltr; unicode-bidi:isolate; }
        .name { max-width:100%; overflow-wrap:anywhere; }
        @page { size:{{ $paperWidth }}mm {{ $paperHeight }}mm; margin:5mm; }
        @media print { .controls { display:none; } body { margin:0; } .label { border:0; break-inside:avoid; } }
    .fox-inventory-swatch { display: inline-block; width: 8px; height: 8px; border: 1px solid #a8b9b1; border-radius: 50%; }
</style>
</head>
<body>
    <div class="controls">
        @if($svg)<button type="button" onclick="window.print()">{{ __('Print barcode label') }}</button>@endif
        <p>{{ __('Print at actual size. Verify the label with your physical scanner.') }}</p>
        @if($format === 'C128B' && preg_match('/^[0-9]{13}$/D', $variant->barcode))
            <p>{{ __('Invalid EAN-13 checksum. Printed as Code 128 with the exact stored value.') }}</p>
        @endif
    </div>
    <article class="label">
        <div class="name">{{ $variant->product->name }}</div>
        <div>{{ __('Size') }}: {{ $variant->size?->name ?? __('No size') }} / {{ __('Color') }}: <x-inventory-color :color="$variant->color" /></div>
        @if($svg){!! $svg !!}@else<p>{{ __('Barcode unavailable') }}</p>@endif
        <div class="identifier">{{ $variant->barcode ?: __('No barcode') }}</div>
        <div class="identifier">{{ __('SKU') }}: {{ $variant->sku }}</div>
    </article>
</body>
</html>
