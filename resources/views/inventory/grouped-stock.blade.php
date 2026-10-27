<div class="fox-inventory-grouped-stock">
    <x-inventory-filter-toolbar :count="$this->getTable()->getActiveFiltersCount()">
        @include('inventory.workspace-filters')
    </x-inventory-filter-toolbar>
    @if ($this->getTable()->getActiveFiltersCount())<p class="fox-inventory-filter-note" role="status">{{ __('Active filters') }}: {{ $this->getTable()->getActiveFiltersCount() }} · {{ __('Product totals reflect matching variants only.') }}</p>@endif
    @include('inventory.product-groups', ['groups' => $groups, 'kind' => $kind])
    @if ($kind === 'balances')
        <x-filament-actions::modals />
    @endif
</div>
