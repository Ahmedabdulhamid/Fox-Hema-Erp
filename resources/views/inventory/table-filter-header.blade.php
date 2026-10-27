<x-inventory-filter-toolbar :count="$inventoryLivewire->getTable()->getActiveFiltersCount()">
    @include('inventory.workspace-filters')
</x-inventory-filter-toolbar>
