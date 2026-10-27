<div class="fox-inventory-movement-workspace">
    <x-inventory-filter-toolbar :count="$this->getTable()->getActiveFiltersCount()">
        @include('inventory.workspace-filters')
        <x-slot:actions>
            <label class="fox-smart-sort"><span class="fi-sr-only">{{ __('Sort by') }}</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="movementSort">@foreach (['latest' => 'Latest movement', 'oldest' => 'Oldest movement', 'incoming' => 'Incoming quantity', 'outgoing' => 'Outgoing quantity'] as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <label class="fox-smart-sort"><span class="fi-sr-only">{{ __('Records per page') }}</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="movementPerPage">@foreach ([25, 50, 100] as $perPage)<option value="{{ $perPage }}">{{ $perPage }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
        </x-slot:actions>
    </x-inventory-filter-toolbar>
    @php($groups = $this->movementProducts())
    <section class="fox-inventory-panel">
        <div class="fox-inventory-scroll" tabindex="0" role="region" aria-label="{{ __('Stock Movements') }}">
            <table class="fox-smart-table">
                <thead><tr><th>{{ __('Product') }}</th><th>{{ __('Colors / sizes') }}</th><th>{{ __('Location') }}</th><th>{{ __('Incoming quantity') }}</th><th>{{ __('Outgoing quantity') }}</th><th>{{ __('Latest movement') }}</th><th>{{ __('Actions') }}</th></tr></thead>
                <tbody>@forelse ($groups as $group)
                    <tr wire:key="movement-product-{{ $group->product_id }}-{{ $group->location_id }}">
                        <td><button class="fox-inventory-product-link" type="button" wire:click="mountAction('movementHistory', { product: {{ $group->product_id }}, location: {{ $group->location_id }} })">@include('components.inventory-product', ['variant' => $group->variant, 'grouped' => true])</button><small>{{ __(':count matching variants', ['count' => $group->variant_count]) }}</small></td>
                        <td><div class="fox-inventory-variant-list">@foreach ($group->variants->take(4) as $variant)<x-inventory-variant :variant="$variant" />@endforeach @if ($group->variants->count() > 4)<small>{{ __('+:count variants', ['count' => $group->variants->count() - 4]) }}</small>@endif</div></td>
                        <td>{{ $group->location_name }}</td>
                        <td><span class="fox-inventory-quantity fox-inventory-quantity-in"><bdi>+{{ \App\Models\QuantityFormatter::format((string) $group->incoming) }}</bdi></span></td>
                        <td><span class="fox-inventory-quantity fox-inventory-quantity-out"><bdi>{{ (float) $group->outgoing > 0 ? '−' : '' }}{{ \App\Models\QuantityFormatter::format((string) $group->outgoing) }}</bdi></span></td>
                        <td><bdi>{{ substr((string) $group->latest_at, 0, 16) }}</bdi></td>
                        <td><x-filament::button size="sm" icon="heroicon-o-eye" wire:click="mountAction('movementHistory', { product: {{ $group->product_id }}, location: {{ $group->location_id }} })">{{ __('View Details') }}</x-filament::button></td>
                    </tr>
                @empty<tr><td colspan="7" class="fox-inventory-empty">{{ __('No matching inventory records') }}</td></tr>@endforelse</tbody>
            </table>
        </div>
        <div class="fox-smart-pagination">{{ $groups->links() }}</div>
    </section>
    <x-filament-actions::modals />
</div>
