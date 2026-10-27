@props(['variant'])
<span class="fox-inventory-variant">
    <x-inventory-color :color="$variant?->color" />
    <span>{{ $variant?->isUnallocated() ? __('Unallocated pieces') : ($variant?->size?->name ?? __('No size')) }}</span>
</span>
