@props(['brand' => null, 'color' => null, 'hex' => null, 'size' => null])

@php
    $missing = __('reporting.variant_fields.missing');
    $brandName = filled($brand) ? $brand : $missing;
    $colorName = filled($color) ? $color : $missing;
    $sizeName = filled($size) ? $size : $missing;
@endphp

<span class="reporting-variant-attributes">
    <span>{{ $brandName }}</span>
    <span class="reporting-variant-attributes__separator" aria-hidden="true">|</span>
    <x-inventory-color :name="$colorName" :hex="$hex" />
    <span class="reporting-variant-attributes__separator" aria-hidden="true">|</span>
    <span>{{ $sizeName }}</span>
</span>
