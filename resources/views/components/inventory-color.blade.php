@props(['color' => null, 'name' => null, 'hex' => null])
@php
    $normalizedHex = \App\Models\ColorPresentation::hex($hex ?? data_get($color, 'hex_code'));
    $colorName = $name ?? data_get($color, 'name') ?? __('No color');
@endphp
<span class="fox-inventory-color">
    <span @class(['fox-inventory-swatch', 'fox-color-unknown' => $normalizedHex === null]) @if($normalizedHex !== null) style="background-color: {{ $normalizedHex }}" @endif aria-hidden="true"></span>
    <span>{{ $colorName }}</span>
</span>
