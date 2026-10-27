<nav class="fox-allocation-primary-tabs" aria-label="{{ __('Stock presentation') }}">
    <button type="button" wire:click="switchStockView('products')" aria-pressed="{{ $this->stockView === 'products' ? 'true' : 'false' }}">{{ __('Products') }}</button>
    <button type="button" wire:click="switchStockView('records')" aria-pressed="{{ $this->stockView === 'records' ? 'true' : 'false' }}">{{ $this->getResource() === \App\Modules\Inventory\Filament\Resources\QuarantineReceipts\QuarantineReceiptResource::class ? __('Custody records') : __('Variant records') }}</button>
</nav>
