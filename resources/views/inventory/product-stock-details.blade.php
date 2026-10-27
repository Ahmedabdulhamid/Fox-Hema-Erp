<div class="fox-inventory-page space-y-4">
    <header class="fox-inventory-drawer-heading"><img width="64" height="64" src="{{ app(\App\Modules\Products\Services\CommercialMedia::class)->publicUrl($product->images->whereNull('product_variant_id')->where('disk', 'public')->sortBy('sort_order')->sortByDesc('is_primary')->first()?->path, 'products/') }}" alt=""><div><h2>{{ $product->name }}</h2><p>{{ $location->name }} · <bdi>{{ $product->default_sku }}</bdi></p></div></header>
    <nav class="fox-inventory-detail-tabs" aria-label="{{ __('Product details') }}">
        @foreach (['overview' => 'Overview', 'branches' => 'Stock by location', 'sizes' => 'Colors and sizes'] as $tab => $label)<button type="button" wire:click="selectInventoryDrawerTab('{{ $tab }}')" aria-pressed="{{ $this->inventoryDrawerTab === $tab ? 'true' : 'false' }}">{{ __($label) }}</button>@endforeach
    </nav>
    @if ($this->inventoryDrawerTab === 'overview')
        <div class="fox-inventory-detail-kpis">
            @foreach (['on_hand' => 'Total on hand', 'available' => 'Total available', 'unallocated' => 'Unallocated pieces'] as $field => $label)<article><span>{{ __($label) }}</span><strong><bdi>{{ \App\Models\QuantityFormatter::format((string) $summary[$field]) }}</bdi></strong></article>@endforeach
            <article><span>{{ __('Locations') }}</span><strong>{{ $locations->count() }}</strong></article>
        </div>
        @include('inventory.product-location-stock', ['branches' => $locationGroups, 'inDrawer' => true])
    @elseif ($this->inventoryDrawerTab === 'branches')
        @include('inventory.product-location-stock', ['branches' => $locationGroups, 'inDrawer' => true])
    @elseif ($matrix)
        <label class="fox-inventory-detail-location">{{ __('Location') }}<x-filament::input.wrapper><x-filament::input.select wire:change="selectInventoryDrawerLocation($event.target.value)">@foreach ($locations as $option)<option value="{{ $option->id }}" @selected($option->id === $location->id)>{{ $option->name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
        @include('inventory.unallocated-stock', ['sources' => $matrix['unallocated']])
        <section class="fox-inventory-panel fox-inventory-matrix"><header><h3>{{ __('Color × Size') }} · {{ __('Available') }}</h3></header>@include('inventory.color-size-matrix')</section>
        @if ($matrix['colors']->hasPages())<p>{{ __('The drawer shows the first 12 colors. Open product inventory for all colors and stock metrics.') }}</p>@endif
    @endif
    <x-filament::button tag="a" :href="\App\Modules\Inventory\Filament\Pages\ProductInventory::getUrl(['productUuid' => $product->uuid, 'location' => $location->id])">{{ __('View full product inventory') }}</x-filament::button>
</div>
