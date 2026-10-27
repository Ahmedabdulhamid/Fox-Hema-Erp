<span class="fox-variant-summary">
    <x-inventory-color :color="$variant?->color" />
    <span>{{ $variant?->isUnallocated() ? __('Unallocated pieces') : ($variant?->size?->name ?? __('No size')) }}</span>
</span>
