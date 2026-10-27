@php($filtersOwner = $inventoryLivewire ?? $this)
<x-filament-tables::filters
    :apply-action="$filtersOwner->getTable()->getFiltersApplyAction()"
    :form="$filtersOwner->getTableFiltersForm()"
    :reset-action="$filtersOwner->getTable()->getFiltersResetAction()"
    :reset-action-position="$filtersOwner->getTable()->getFiltersResetActionPosition()"
/>
