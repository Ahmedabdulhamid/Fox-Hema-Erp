<section class="fox-inventory-panel fox-inventory-product-groups" aria-label="{{ __('Products by location') }}">
    <div class="fox-inventory-scroll" tabindex="0" role="region" aria-label="{{ __('Products by location') }}">
        <table class="fox-smart-table">
            <thead><tr><th>{{ __('Product') }}</th><th>{{ __('Location') }}</th><th>{{ $kind === 'quarantine' ? __('Quarantined variant stock') : __('Variant stock') }}</th>
                @if ($kind !== 'quarantine')<th>{{ __('Reserved') }}</th><th>{{ __('Available') }}</th>@endif
                @if ($kind === 'insights')<th>{{ __('Net units sold') }}</th><th>{{ __('Last sale') }}</th>@endif
                <th>{{ __('Unallocated pieces') }}</th><th>{{ __('Status') }}</th><th>{{ __('Actions') }}</th>
            </tr></thead>
            <tbody>
                @forelse ($groups as $group)
                    <tr wire:key="product-group-{{ $kind }}-{{ $group->product_id }}-{{ $group->location_id }}">
                        <td>@if ($kind !== 'quarantine')<button type="button" class="fox-inventory-product-link" wire:click="mountAction('productDetails', { product: {{ $group->product_id }}, location: {{ $group->location_id }} })">@include('components.inventory-product', ['variant' => $group->variant, 'grouped' => true])</button>@else @include('components.inventory-product', ['variant' => $group->variant, 'grouped' => true]) @endif<small>{{ __(':count matching variants', ['count' => $group->variants]) }}</small></td>
                        <td>{{ $group->location_name }}</td>
                        @foreach ($kind === 'quarantine' ? ['quantity_on_hand'] : ['quantity_on_hand', 'quantity_reserved', 'quantity_available'] as $quantity)
                            <td><bdi>{{ $group->$quantity === null ? __('Missing balance') : \App\Models\QuantityFormatter::format((string) $group->$quantity) }}</bdi>@if ($group->missing_balances)<small>{{ __('Missing balance') }}: {{ $group->missing_balances }}</small>@endif</td>
                        @endforeach
                        @if ($kind === 'insights')<td><bdi>{{ \App\Models\QuantityFormatter::format((string) $group->net_sold) }}</bdi></td><td>{{ $group->last_sale_at ? \Illuminate\Support\Carbon::parse($group->last_sale_at)->format('Y-m-d') : __('No posted sales history') }}</td>@endif
                        <td class="fox-inventory-unallocated-quantity"><bdi>{{ $group->unallocated_missing_balances ? __('Missing balance') : \App\Models\QuantityFormatter::format((string) ($group->unallocated_quantity_on_hand ?? '0')) }}</bdi><small>{{ $kind === 'quarantine' ? __('Quarantined; unavailable for allocation') : __('Separate from variant stock') }}</small></td>
                        <td>@if ($kind === 'quarantine')<x-filament::badge color="warning">{{ __('returns.quarantine') }}</x-filament::badge>@elseif ($group->pool)<x-filament::badge :color="$group->pool->allocation_state === 'full' ? 'success' : 'warning'">{{ \App\Modules\Inventory\Livewire\AllocationStockTable::statuses()[$group->pool->allocation_state] }}</x-filament::badge>@elseif ($group->missing_balances)<x-filament::badge color="gray">{{ __('Missing balance') }}</x-filament::badge>@else<x-filament::badge color="info">{{ __('Recorded stock') }}</x-filament::badge>@endif</td>
                        <td><div class="fox-inventory-row-actions">
                            @if ($kind === 'quarantine')
                                <x-filament::button color="gray" size="sm" wire:click="showProductRecords({{ $group->product_id }}, {{ $group->location_id }})">{{ __('Custody records') }}</x-filament::button>
                            @else
                                <x-filament::button :color="$kind === 'balances' ? 'primary' : 'gray'" :icon="$kind === 'balances' ? 'heroicon-o-eye' : null" size="sm" wire:click="mountAction('productDetails', { product: {{ $group->product_id }}, location: {{ $group->location_id }} })">{{ __('View Details') }}</x-filament::button>
                                @if ($group->pool && app(\App\Modules\Inventory\Services\InventoryProductPresentation::class)->canAllocate(auth()->user(), $group->pool))
                                    <x-filament::button size="sm" tag="a" :href="\App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource::getUrl('create', ['product' => $group->product_id, 'location' => $group->location_id])">{{ __('Allocate Now') }}</x-filament::button>
                                @endif
                            @endif
                        </div></td>
                    </tr>
                @empty<tr><td colspan="{{ $kind === 'quarantine' ? 6 : ($kind === 'insights' ? 10 : 8) }}" class="fox-inventory-empty">{{ __('No matching inventory records') }}<small>{{ __('Try another location or adjust your filters. No stock quantities have been changed.') }}</small></td></tr>@endforelse
            </tbody>
        </table>
    </div>
    <div class="fox-smart-pagination">{{ $groups->links() }}</div>
</section>
