<div class="reporting-filter-actions">
    <x-filament::button
        type="submit"
        icon="heroicon-o-funnel"
        wire:loading.attr="disabled"
        wire:target="applyFilters,resetFilters"
    >
        {{ __('reporting.filters.apply') }}
    </x-filament::button>
    <x-filament::button
        type="button"
        color="gray"
        icon="heroicon-o-arrow-path"
        wire:click="resetFilters"
        wire:loading.attr="disabled"
        wire:target="applyFilters,resetFilters"
    >
        {{ __('reporting.filters.reset') }}
    </x-filament::button>
</div>
