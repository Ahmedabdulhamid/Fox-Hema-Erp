@foreach ($sources as $source)
    <section class="fox-inventory-unallocated-card" aria-label="{{ __('Unallocated pieces') }}">
        <div><h3>{{ __('Unallocated pieces') }}</h3><p>{{ __('Separate from the color and size matrix. Existing stock records are preserved.') }}</p><bdi>{{ $source->sku }}</bdi></div>
        <dl class="fox-allocation-detail-metrics">
            @foreach (['quantity_on_hand' => 'On hand', 'quantity_reserved' => 'Reserved', 'quantity_available' => 'Available'] as $field => $label)
                <div><dt>{{ __($label) }}</dt><dd><bdi>{{ $source->$field === null ? __('Missing balance') : \App\Models\QuantityFormatter::format((string) $source->$field) }}</bdi></dd></div>
            @endforeach
        </dl>
        @if ($allocationPool ?? null)<x-filament::badge color="warning">{{ \App\Modules\Inventory\Livewire\AllocationStockTable::statuses()[$allocationPool->allocation_state] }}</x-filament::badge>@endif
        @if (app(\App\Modules\Inventory\Services\InventoryAccess::class)->allows(auth()->user(), \App\Modules\Inventory\Services\InventoryPermissions::ALLOCATIONS_VIEW, (int) $source->inventory_location_id))
            <div class="fox-inventory-row-actions">
                @if (($allocationPool ?? null) && app(\App\Modules\Inventory\Services\InventoryProductPresentation::class)->canAllocate(auth()->user(), $allocationPool))
                    <x-filament::button tag="a" size="sm" :href="\App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource::getUrl('create', ['product' => $source->product_id, 'location' => $source->inventory_location_id])">{{ __('Allocate Now') }}</x-filament::button>
                @endif
                <x-filament::link :href="\App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource::getUrl('index', ['workspace' => 'management'])">{{ __('View allocation status') }}</x-filament::link>
            </div>
        @endif
    </section>
@endforeach
