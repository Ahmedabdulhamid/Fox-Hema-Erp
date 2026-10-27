@php
    $variantsBySku = $product->variants()->with('color')->get(['sku', 'color_id'])->keyBy('sku');
@endphp
<div class="space-y-6">
    <h2 class="text-lg font-semibold">{{ $product->name }}</h2>
    @forelse ($locations as $location)
        <section class="space-y-3">
            <h3 class="font-semibold">{{ $location['name'] }}</h3>
            <x-filament::link :href="\App\Modules\Inventory\Filament\Pages\ProductInventory::getUrl(['productUuid' => $product->uuid, 'location' => $location['id']])">{{ __('Explore color and size matrix') }}</x-filament::link>
            <p>{{ __('Total stock across sizes') }}: {{ \App\Models\NumericFormatter::format($location['total'], 'quantity') }} · {{ __('Unallocated pieces') }}: {{ \App\Models\NumericFormatter::format($location['unallocated'], 'quantity') }}</p>
            <div class="fox-inventory-unallocated-card"><h3>{{ __('Unallocated pieces') }}: <bdi>{{ \App\Models\NumericFormatter::format($location['unallocated'], 'quantity') }}</bdi></h3><p>{{ __('Separate from the color and size matrix. Existing stock records are preserved.') }}</p></div>
            <div class="fox-inventory-scroll"><table class="fox-smart-table">
                <thead><tr><th class="text-start">{{ __('Size') }}</th><th class="text-start">{{ __('SKU') }}</th><th class="text-start">{{ __('Quantity on hand') }}</th><th class="text-start">{{ __('Quantity available') }}</th></tr></thead>
                <tbody>
                    @foreach ($location['rows'] as $row)
                        @unless ($row['is_unallocated'])<tr><td class="py-2"><x-inventory-color :color="$variantsBySku->get($row['sku'])?->color" :name="$row['color']" /> · {{ $row['label'] }}</td><td>{{ $row['sku'] }}</td><td>{{ \App\Models\NumericFormatter::format($row['on_hand'], 'quantity') }}</td><td>{{ \App\Models\NumericFormatter::format($row['available'], 'quantity') }}</td></tr>@endunless
                    @endforeach
                </tbody>
            </table></div>
            @if (app(\App\Modules\Inventory\Services\InventoryAccess::class)->allows(auth()->user(), \App\Modules\Inventory\Services\InventoryPermissions::ALLOCATIONS_VIEW, $location['id']))
                @if (\Brick\Math\BigDecimal::of($location['unallocated'])->isPositive() && app(\App\Modules\Inventory\Services\InventoryAccess::class)->allows(auth()->user(), \App\Modules\Inventory\Services\InventoryPermissions::ALLOCATIONS_MANAGE, $location['id']))
                    <x-filament::link :href="\App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource::getUrl('create', ['product' => $product->id, 'location' => $location['id']])">{{ __('Allocate Now') }}</x-filament::link>
                @endif
                <x-filament::link :href="\App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource::getUrl('index', ['workspace' => 'history', 'filters' => ['product_id' => ['value' => $product->id], 'location_id' => ['value' => $location['id']]]])">{{ __('Allocation history') }}</x-filament::link>
            @endif
        </section>
    @empty
        <p>{{ __('No recorded stock balances.') }}</p>
    @endforelse
</div>
