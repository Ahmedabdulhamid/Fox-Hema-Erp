<div class="fox-inventory-overview" wire:loading.class="fox-inventory-updating" role="region" aria-label="{{ __('Inventory overview') }}">
    @if ($kind !== 'movements')
        <div class="fox-inventory-eyebrow">
            <span><span class="fox-inventory-live-dot" aria-hidden="true"></span>{{ __('Inventory overview') }}</span>
            <span wire:loading.remove>{{ __('All matching records') }} @if (\App\Modules\Inventory\Filament\Pages\InventoryOverview::canAccess()) · <a href="{{ \App\Modules\Inventory\Filament\Pages\InventoryOverview::getUrl() }}">{{ __('Inventory Overview') }}</a>@endif</span>
            <span wire:loading role="status">{{ __('Updating inventory view…') }}</span>
        </div>
    @endif
    <x-inventory-kpis :cards="$cards" />
    @if ($kind === 'quarantine')
        <div class="fox-inventory-custody-note">@svg('heroicon-o-shield-check')<span>{{ __('Observation only: quarantined stock remains separate until an authorized disposition is supported.') }}</span></div>
    @endif
    @if ($matrix !== null)
        @include('inventory.size-matrix', ['matrix' => $matrix])
    @endif
</div>
