<?php

namespace App\Modules\Products\Filament;

use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\VariantInventoryValuation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class VariantInventoryValuationPanel extends Component
{
    use WithPagination;

    #[Locked]
    public int $variantId;

    #[Locked]
    public int $productId;

    public ?string $locationId = null;

    public function mount(int $variantId, int $productId): void
    {
        $this->variantId = $variantId;
        $this->productId = $productId;
        $locations = $this->locations;
        $active = getPermissionsTeamId();
        $default = $active !== null && isset($locations[$active]) ? $active : array_key_first($locations);
        $this->locationId = $default === null ? null : (string) $default;
    }

    #[Computed]
    public function variant(): ProductVariant
    {
        $variant = ProductVariant::with(['product', 'color', 'size'])->where('product_id', $this->productId)->findOrFail($this->variantId);
        app(VariantInventoryValuation::class)->authorize(auth()->user(), $variant);

        return $variant;
    }

    #[Computed]
    public function locations(): array
    {
        $this->variant;

        return app(VariantInventoryValuation::class)->locations(auth()->user())->orderBy('name')->orderBy('id')->pluck('name', 'id')->all();
    }

    public function updatedLocationId(): void
    {
        unset($this->valuation);
        $this->resetPage('valuationLayers');
    }

    #[Computed]
    public function valuation(): ?array
    {
        $locations = $this->locations;
        if ($this->locationId === null || $this->locationId === '') {
            return null;
        }
        abort_unless(ctype_digit($this->locationId) && isset($locations[(int) $this->locationId]), 403);

        return app(VariantInventoryValuation::class)->read(auth()->user(), $this->variant,
            (int) $this->locationId, $this->getPage('valuationLayers'));
    }

    public function render(): View
    {
        return view('components.variant-inventory-valuation', ['valuation' => $this->valuation, 'locations' => $this->locations]);
    }
}
