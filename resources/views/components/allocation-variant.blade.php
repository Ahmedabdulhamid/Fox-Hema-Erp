<span class="fox-variant-summary">
    <span>{{ $sizeName ?? $variant?->size?->name ?? __('No size') }}</span>
    <x-inventory-color :color="$variant?->color" :name="$colorName" />
</span>
