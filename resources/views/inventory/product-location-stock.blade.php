<section class="fox-inventory-panel">
    <header><h3>{{ __('Stock by location') }}</h3></header>
    <div class="fox-inventory-scroll" tabindex="0" role="region" aria-label="{{ __('Stock by location') }}"><table class="fox-smart-table">
        <thead><tr><th>{{ __('Location') }}</th><th>{{ __('Total on hand') }}</th><th>{{ __('Variant stock') }}</th><th>{{ __('Unallocated pieces') }}</th><th>{{ __('Actions') }}</th></tr></thead>
        <tbody>@forelse ($branches as $branch)<tr><th>{{ $branch->location_name }}</th><td><bdi>{{ $branch->total_quantity_on_hand === null ? __('Missing balance') : \App\Models\QuantityFormatter::format((string) $branch->total_quantity_on_hand) }}</bdi></td><td><bdi>{{ $branch->quantity_on_hand === null ? '—' : \App\Models\QuantityFormatter::format((string) $branch->quantity_on_hand) }}</bdi></td><td><bdi>{{ $branch->unallocated_missing_balances ? __('Missing balance') : \App\Models\QuantityFormatter::format((string) ($branch->unallocated_quantity_on_hand ?? '0')) }}</bdi></td>
            <td><div class="fox-inventory-row-actions"><x-filament::button color="gray" size="sm" wire:click="{{ ($inDrawer ?? false) ? 'selectInventoryDrawerLocation' : 'selectProductLocation' }}({{ $branch->location_id }})">{{ __('Colors and sizes') }}</x-filament::button>
                @if ($branch->pool && app(\App\Modules\Inventory\Services\InventoryProductPresentation::class)->canAllocate(auth()->user(), $branch->pool))<x-filament::button size="sm" tag="a" :href="\App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource::getUrl('create', ['product' => $branch->product_id, 'location' => $branch->location_id])">{{ __('Allocate Now') }}</x-filament::button>@endif
            </div></td>
        </tr>@empty<tr><td colspan="5">{{ __('No matching inventory records') }}</td></tr>@endforelse</tbody>
    </table></div>
    @if ($inDrawer ?? false)
        <div class="fox-inventory-row-actions fox-smart-pagination">@if ($branches->currentPage() > 1)<x-filament::button color="gray" size="sm" wire:click="productDrawerPage({{ $branches->currentPage() - 1 }})">{{ __('Previous') }}</x-filament::button>@endif @if ($branches->hasMorePages())<x-filament::button color="gray" size="sm" wire:click="productDrawerPage({{ $branches->currentPage() + 1 }})">{{ __('Next') }}</x-filament::button>@endif</div>
    @else<div class="fox-smart-pagination">{{ $branches->links() }}</div>@endif
</section>
