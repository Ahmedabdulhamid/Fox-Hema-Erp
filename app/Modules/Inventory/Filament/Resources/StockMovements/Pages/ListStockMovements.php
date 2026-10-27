<?php

namespace App\Modules\Inventory\Filament\Resources\StockMovements\Pages;

use App\Modules\Inventory\Filament\InventoryListRecords;
use App\Modules\Inventory\Filament\Resources\StockMovements\StockMovementResource;
use App\Modules\Inventory\Services\InventoryMovementPresentation;
use Filament\Actions\Action;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Pagination\LengthAwarePaginator;

class ListStockMovements extends InventoryListRecords
{
    protected string $inventoryKind = 'movements';

    public function getSubheading(): ?string
    {
        return null;
    }

    protected static string $resource = StockMovementResource::class;

    public string $movementSort = 'latest';

    public int $movementPerPage = 25;

    public int $movementHistoryPage = 1;

    public function content(Schema $schema): Schema
    {
        if ($this->stockView === 'records') {
            return parent::content($schema);
        }

        return $schema->components([
            View::make('inventory.workspace-overview')->viewData(fn (): array => $this->inventoryOverview()),
            View::make('inventory.movement-products'),
        ]);
    }

    public function movementProducts(): LengthAwarePaginator
    {
        return app(InventoryMovementPresentation::class)->products($this->getFilteredTableQuery(), auth()->user(),
            $this->getPage($this->getTablePaginationPageName()), $this->movementSort, $this->movementPerPage, $this->getTablePaginationPageName());
    }

    public function updatedMovementSort(): void
    {
        $this->resetPage($this->getTablePaginationPageName());
    }

    public function updatedMovementPerPage(): void
    {
        $this->resetPage($this->getTablePaginationPageName());
    }

    public function movementHistoryPage(int $page): void
    {
        abort_unless($page > 0 && $page <= 100000, 422);
        $this->movementHistoryPage = $page;
    }

    public function movementHistoryAction(): Action
    {
        return Action::make('movementHistory')->label(__('View Details'))->icon('heroicon-o-eye')->slideOver()->modalWidth('7xl')
            ->modalHeading(__('Product movement history'))->modalSubmitAction(false)
            ->mountUsing(function (): void {
                $this->movementHistoryPage = 1;
            })
            ->modalContent(function (array $arguments): ViewContract {
                $product = filter_var($arguments['product'] ?? null, FILTER_VALIDATE_INT);
                $location = filter_var($arguments['location'] ?? null, FILTER_VALIDATE_INT);
                abort_unless($product && $location, 404);

                return view('inventory.product-movement-history', app(InventoryMovementPresentation::class)
                    ->history($this->getFilteredTableQuery(), auth()->user(), $product, $location, $this->movementHistoryPage));
            });
    }

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...parent::getPageClasses(), 'fox-stock-movements-page'];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
