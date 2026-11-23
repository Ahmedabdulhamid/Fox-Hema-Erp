@props(['variant'])
<span class="fox-inventory-variant">
    @if ($variant?->isUnallocated())
        <span>{{ __('Unallocated pieces') }} — {{ __('Sizes Not Yet Allocated') }}</span>
    @else
        <x-inventory-color :color="$variant?->color" />
        <span>{{ $variant?->size?->name ?? __('No size') }}</span>
    @endif
</span>
