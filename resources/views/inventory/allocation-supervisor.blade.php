<div class="fox-allocation-workspace">
    <nav class="fox-allocation-primary-tabs" aria-label="{{ __('Size Allocations') }}">
        @foreach (['management' => 'Allocation Management', 'history' => 'Allocation History'] as $key => $label)
            <button type="button" wire:click="switchWorkspace('{{ $key }}')" aria-pressed="{{ $this->workspaceTab === $key ? 'true' : 'false' }}" aria-controls="allocation-{{ $key }}">{{ __($label) }}</button>
        @endforeach
        @if (\App\Modules\Inventory\Filament\Pages\InventoryOverview::canAccess())
            <x-filament::link :href="\App\Modules\Inventory\Filament\Pages\InventoryOverview::getUrl()">{{ __('View Inventory Analytics') }}</x-filament::link>
        @endif
    </nav>
    @if ($this->workspaceTab === 'management')
        <section id="allocation-management" aria-label="{{ __('Allocation Management') }}" wire:key="allocation-management">
            @if (app(\App\Modules\Inventory\Services\InventoryAccess::class)->allows(auth()->user(), \App\Modules\Inventory\Services\InventoryPermissions::BALANCES))
                @livewire(\App\Modules\Inventory\Livewire\AllocationStockTable::class, [], key('allocation-readiness'))
            @else
                <div class="fox-inventory-panel"><h2>{{ __('Stock balance access required') }}</h2><p>{{ __('Allocation history remains available with your existing permissions.') }}</p></div>
            @endif
        </section>
    @elseif ($this->workspaceTab === 'history')
        <div id="allocation-history" class="fox-allocation-history-note" wire:key="allocation-history"><p>{{ __('Document status describes draft, posted or cancelled documents, not product allocation completion.') }}</p></div>
    @endif
</div>
