<?php

namespace App\Modules\Inventory\Filament;

use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\InventoryProductPresentation;
use Filament\Actions\Action;
use Illuminate\Contracts\View\View;

trait InteractsWithInventoryProducts
{
    public string $inventoryDrawerTab = 'overview';

    public int $inventoryDrawerLocation = 0;

    public int $inventoryDrawerPage = 1;

    public function selectInventoryDrawerTab(string $tab): void
    {
        abort_unless(in_array($tab, ['overview', 'branches', 'sizes'], true), 422);
        $this->inventoryDrawerTab = $tab;
    }

    public function selectInventoryDrawerLocation(int $location): void
    {
        app(InventoryAccess::class)->authorize(auth()->user(), InventoryPermissions::BALANCES, $location);
        $this->inventoryDrawerLocation = $location;
        $this->inventoryDrawerTab = 'sizes';
    }

    public function productDrawerPage(int $page): void
    {
        abort_unless($page > 0 && $page <= 100000, 422);
        $this->inventoryDrawerPage = $page;
    }

    public function productDetailsAction(): Action
    {
        return Action::make('productDetails')->label(__('View Details'))->modal()->slideOver()->modalWidth('5xl')
            ->modalHeading(__('Product Inventory'))->modalSubmitAction(false)
            ->visible(fn (): bool => app(InventoryAccess::class)->allows(auth()->user(), InventoryPermissions::BALANCES))
            ->mountUsing(function (array $arguments): void {
                $this->inventoryDrawerLocation = (int) ($arguments['location'] ?? 0);
                $this->inventoryDrawerPage = 1;
                $this->selectInventoryDrawerTab($arguments['tab'] ?? 'overview');
            })
            ->modalContent(function (array $arguments): View {
                $productId = filter_var($arguments['product'] ?? null, FILTER_VALIDATE_INT);
                abort_unless($productId, 404);

                return view('inventory.product-stock-details', app(InventoryProductPresentation::class)->details(auth()->user(), $productId, $this->inventoryDrawerLocation ?: null, $this->inventoryDrawerTab === 'sizes', $this->inventoryDrawerPage));
            });
    }
}
