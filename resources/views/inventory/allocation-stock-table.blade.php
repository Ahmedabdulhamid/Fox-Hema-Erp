
<div class="fox-allocation-supervisor space-y-4">
    <div class="fox-inventory-kpis">
        @foreach (['needs' => ['Needs Allocation', 'blue'], 'unallocated' => ['Unallocated', 'rose'], 'partial' => ['Partially Allocated', 'amber'], 'full' => ['Fully Allocated', 'teal']] as $key => [$label, $tone])
            <button
                type="button"
                class="fox-inventory-kpi fox-inventory-tone-{{ $tone }} fox-allocation-card"
                wire:click="chooseStatus('{{ $key }}')"
                aria-pressed="{{ ($tableFilters['allocation_state']['value'] ?? 'needs') === $key ? 'true' : 'false' }}"
            >
                <span class="fox-inventory-kpi-icon">
                    @svg('heroicon-o-squares-2x2')
                </span>

                <span>
                    <span class="fox-allocation-card-label">{{ __($label) }}</span>
                    <strong><bdi>{{ $this->counts[$key] }}</bdi></strong>
                </span>
            </button>
        @endforeach
    </div>

    <div class="fox-allocation-controls">
        <div
            class="fox-allocation-tabs"
            role="group"
            aria-label="{{ __('Allocation status') }}"
        >
            @foreach (\App\Modules\Inventory\Livewire\AllocationStockTable::statuses() as $key => $label)
                <button
                    type="button"
                    wire:click="chooseStatus('{{ $key }}')"
                    aria-pressed="{{ ($tableDeferredFilters['allocation_state']['value'] ?? $tableFilters['allocation_state']['value'] ?? 'needs') === $key ? 'true' : 'false' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    @if ($this->hasPendingFilters())
        <p class="fox-smart-pending-note" role="status">
            {{ __('Pending filter selections have not been applied.') }}
        </p>
    @endif

    @if ($this->counts['review'])
        <button
            type="button"
            class="fox-allocation-review"
            wire:click="chooseStatus('review')"
        >
            {{ __('Needs review') }}: {{ $this->counts['review'] }}
        </button>
    @endif

    {{ $this->table }}

    <x-filament-actions::modals />
</div>
