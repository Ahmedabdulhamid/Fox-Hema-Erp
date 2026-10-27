@props(['count' => 0, 'searchModel' => 'tableSearch', 'resetAction' => 'resetTableFiltersForm'])
<div class="fox-inventory-filter-workspace" x-data="{ filtersOpen: false }" x-id="['inventory-filters-panel', 'inventory-filters-heading']" x-on:inventory-filters-applied.window="filtersOpen = false" x-on:keydown.escape.stop="filtersOpen = false">
    <div class="fox-inventory-toolbar">
        <button type="button" class="fox-inventory-filter-trigger" x-ref="filtersTrigger" x-on:click="filtersOpen = ! filtersOpen" x-bind:aria-expanded="filtersOpen" x-bind:aria-controls="$id('inventory-filters-panel')">
            @svg('heroicon-o-funnel') {{ __('Filters') }} <span class="fox-filter-count" aria-label="{{ __('Active filters') }}">{{ $count }}</span>
        </button>
        <label class="fox-inventory-search"><span class="fi-sr-only">{{ __('Search products, SKU or barcode') }}</span><x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass"><x-filament::input wire:model.live.debounce.400ms="{{ $searchModel }}" :placeholder="__('Search products, SKU or barcode')" /></x-filament::input.wrapper></label>
        <x-filament::button color="gray" icon="heroicon-o-arrow-path" wire:click="{{ $resetAction }}">{{ __('Reset Filters') }}</x-filament::button>
        {{ $actions ?? '' }}
    </div>
    <section class="fox-inventory-panel fox-inventory-filters fox-inventory-inline-filters" x-cloak x-show="filtersOpen" x-bind:id="$id('inventory-filters-panel')" x-bind:aria-labelledby="$id('inventory-filters-heading')">
        <header class="fox-inventory-filter-heading">
            <h2 x-bind:id="$id('inventory-filters-heading')">{{ __('Filters') }} <span class="fox-inventory-filter-active">{{ __('Active filters') }}: {{ $count }}</span></h2>
            <button type="button" class="fox-inventory-filter-close" aria-label="{{ __('Close filters') }}" x-on:click="filtersOpen = false; $refs.filtersTrigger.focus()">@svg('heroicon-o-x-mark')</button>
        </header>
        {{ $slot }}
    </section>
</div>
