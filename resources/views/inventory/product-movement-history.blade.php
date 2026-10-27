<div class="fox-inventory-page fox-inventory-movement-drawer">
    <header class="fox-inventory-drawer-heading">@include('components.inventory-product', ['variant' => $productVariant, 'grouped' => true])<span class="fox-inventory-location">@svg('heroicon-o-map-pin') {{ $location->name }}</span></header>
    <details class="fox-inventory-variant-identities"><summary>{{ __('Colors / sizes') }} ({{ $variants->count() }})</summary><div class="fox-inventory-variant-list">@foreach ($variants as $variant)<span><x-inventory-variant :variant="$variant" /> <bdi>{{ $variant->sku }}</bdi></span>@endforeach</div></details>
    <div class="fox-inventory-kpis">
        @foreach (['incoming' => ['Incoming quantity', 'teal'], 'outgoing' => ['Outgoing quantity', 'rose'], 'net' => ['Net quantity change', 'blue']] as $key => [$label, $tone])
            <article class="fox-inventory-kpi fox-inventory-tone-{{ $tone }}"><div><h2>{{ __($label) }}</h2><strong><bdi>{{ \App\Models\QuantityFormatter::format((string) $summary->$key) }}</bdi></strong></div></article>
        @endforeach
        @if ($canViewBalance)
            <article class="fox-inventory-kpi fox-inventory-tone-teal"><div><h2>{{ __('Current stock (matching variants)') }}</h2><strong><bdi>{{ $currentBalance === null ? __('Missing balance') : \App\Models\QuantityFormatter::format((string) $currentBalance) }}</bdi></strong></div></article>
        @endif
    </div>
    <p class="fox-inventory-filter-note">{{ __('Historical totals for the selected filters; not a current stock balance.') }}</p>
    <section class="fox-inventory-panel">
        <header><h3>{{ __('Product movement history') }} ({{ $summary->count }})</h3></header>
        <div class="fox-inventory-scroll" tabindex="0" role="region" aria-label="{{ __('Product movement history') }}">
            <table class="fox-smart-table"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Movement type') }}</th><th>{{ __('Variant') }}</th><th>{{ __('Location') }}</th><th>{{ __('Signed quantity') }}</th><th>{{ __('Reference') }}</th><th>{{ __('Source document') }}</th><th>{{ __('Created by') }}</th><th>{{ __('Actions') }}</th></tr></thead>
                <tbody>@foreach ($movements as $movement)
                    <tr wire:key="history-movement-{{ $movement->id }}">
                        <td><bdi>{{ $movement->created_at->format('Y-m-d H:i') }}</bdi></td>
                        <td><span class="fox-inventory-movement-type fox-inventory-movement-{{ $movement->type->value }}">{{ __(ucwords(str_replace('_', ' ', $movement->type->value))) }}</span></td>
                        <td><x-inventory-variant :variant="$movement->variant" /><small><bdi>{{ $movement->variant->sku }}</bdi></small></td>
                        <td>{{ $location->name }}</td>
                        <td><span class="fox-inventory-quantity {{ (float) $movement->quantity < 0 ? 'fox-inventory-quantity-out' : 'fox-inventory-quantity-in' }}"><bdi>{{ (float) $movement->quantity > 0 ? '+' : '' }}{{ \App\Models\QuantityFormatter::format((string) $movement->quantity) }}</bdi></span></td>
                        <td><bdi>{{ $movement->display_reference }}</bdi></td>
                        <td><bdi>{{ $movement->inventorySourceDocument?->display_reference ?? '—' }}</bdi></td>
                        <td>{{ $movement->createdBy?->name ?? $movement->system_actor ?? __('Unknown actor') }}</td>
                        <td><details class="fox-inventory-movement-metadata"><summary>{{ __('View Details') }}</summary>@include('inventory.movement-details', ['record' => $movement])</details></td>
                    </tr>
                @endforeach</tbody>
            </table>
        </div>
        <nav class="fox-smart-pagination" aria-label="{{ __('Product movement history') }}">
            <x-filament::button color="gray" size="sm" :disabled="$movements->onFirstPage()" wire:click="movementHistoryPage({{ max(1, $movements->currentPage() - 1) }})">{{ __('Previous') }}</x-filament::button>
            <span>{{ $movements->currentPage() }} / {{ $movements->lastPage() }}</span>
            <x-filament::button color="gray" size="sm" :disabled="! $movements->hasMorePages()" wire:click="movementHistoryPage({{ $movements->currentPage() + 1 }})">{{ __('Next') }}</x-filament::button>
        </nav>
    </section>
</div>
