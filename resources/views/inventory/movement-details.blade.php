<div class="fox-inventory-page space-y-4">
    @include('components.inventory-product', ['variant' => $record->variant])
    <dl class="fox-smart-facts">
        @foreach (['Reference' => $record->display_reference, 'Location' => $record->location->name, 'Variant' => $record->variant->isUnallocated() ? __('Unallocated pieces') : $record->variant->imageLabel(), 'Movement type' => __(ucwords(str_replace('_', ' ', $record->type->value))), 'Signed quantity' => \App\Models\QuantityFormatter::format((string) $record->quantity), 'Created at' => $record->created_at->format('Y-m-d H:i'), 'Created by' => $record->createdBy?->name ?? $record->system_actor ?? __('Unknown actor')] as $label => $value)<div><dt>{{ __($label) }}</dt><dd>@if ($label === 'Variant')<x-inventory-variant :variant="$record->variant" />@else{{ $value }}@endif</dd></div>@endforeach
        @if (app(\App\Modules\Inventory\Services\InventoryAccess::class)->allows(auth()->user(), \App\Modules\Inventory\Services\InventoryPermissions::COST, (int) $record->location_id))<div><dt>{{ __('Unit cost') }}</dt><dd>{{ \App\Models\NumericFormatter::format($record->unit_cost, 'precise') }}</dd></div>@endif
    </dl>
    @if (filled($record->notes))
        <p>{{ $record->notes }}</p>
    @endif
    @if (\App\Modules\Inventory\Filament\Pages\InventoryOverview::canAccess())<x-filament::link :href="\App\Modules\Inventory\Filament\Pages\ProductInventory::getUrl(['productUuid' => $record->variant->product->uuid, 'location' => $record->location_id, 'variant' => $record->variant_id])">{{ __('View full product inventory') }}</x-filament::link>@endif
</div>
