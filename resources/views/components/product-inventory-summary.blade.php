<div class="space-y-6">
    <h2 class="text-lg font-semibold">{{ $product->name }}</h2>
    @forelse ($locations as $location)
        <section class="space-y-3">
            <h3 class="font-semibold">{{ $location['name'] }}</h3>
            <p>{{ __('Total stock across sizes') }}: {{ $location['total'] }} · {{ __('Unallocated pieces') }}: {{ $location['unallocated'] }}</p>
            <table class="fox-data-table w-full text-start">
                <thead><tr><th class="text-start">{{ __('Size') }}</th><th class="text-start">{{ __('SKU') }}</th><th class="text-start">{{ __('Quantity on hand') }}</th><th class="text-start">{{ __('Quantity available') }}</th></tr></thead>
                <tbody>
                    @foreach ($location['rows'] as $row)
                        <tr><td class="py-2">{{ $row['label'] }}</td><td>{{ $row['sku'] }}</td><td>{{ $row['on_hand'] }}</td><td>{{ $row['available'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            @if (app(\App\Modules\Inventory\Services\InventoryAccess::class)->allows(auth()->user(), \App\Modules\Inventory\Services\InventoryPermissions::ALLOCATIONS_VIEW, $location['id']))
                @if (\Brick\Math\BigDecimal::of($location['unallocated'])->isPositive() && app(\App\Modules\Inventory\Services\InventoryAccess::class)->allows(auth()->user(), \App\Modules\Inventory\Services\InventoryPermissions::ALLOCATIONS_MANAGE, $location['id']))
                    <x-filament::link :href="\App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource::getUrl('create', ['product' => $product->id, 'location' => $location['id']])">{{ __('Allocate Sizes') }}</x-filament::link>
                @endif
                <x-filament::link :href="\App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource::getUrl('index', ['filters' => ['product_id' => ['value' => $product->id], 'location_id' => ['value' => $location['id']]]])">{{ __('Allocation history') }}</x-filament::link>
            @endif
        </section>
    @empty
        <p>{{ __('No recorded stock balances.') }}</p>
    @endforelse
</div>
