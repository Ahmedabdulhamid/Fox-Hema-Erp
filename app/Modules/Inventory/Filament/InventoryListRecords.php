<?php

namespace App\Modules\Inventory\Filament;

use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\InventoryProductPresentation;
use App\Modules\Inventory\Services\InventoryWorkspace;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;

abstract class InventoryListRecords extends ListRecords
{
    use InteractsWithInventoryProducts;

    protected string $inventoryKind = 'balances';

    #[Url(as: 'stock-view')]
    public string $stockView = 'products';

    public function content(Schema $schema): Schema
    {
        $content = parent::content($schema);

        $overview = View::make('inventory.workspace-overview')->viewData(fn (): array => $this->inventoryOverview());

        return $content->components([
            $overview,
            ...($this->inventoryKind === 'movements' ? $content->getComponents() : [
                View::make('inventory.stock-mode'),
                ...($this->stockView === 'records' ? $content->getComponents() : [View::make('inventory.grouped-stock')->viewData(fn (): array => ['groups' => $this->productGroups(), 'kind' => $this->inventoryKind])]),
            ]),
        ]);
    }

    public function switchStockView(string $view): void
    {
        abort_unless(in_array($view, ['products', 'records'], true), 422);
        $this->stockView = $view;
        unset($this->cachedSchemas['content']);
        $this->resetPage();
    }

    public function applyTableFilters(): void
    {
        parent::applyTableFilters();
        $this->dispatch('inventory-filters-applied');
    }

    public function productGroups(): LengthAwarePaginator
    {
        return app(InventoryProductPresentation::class)->paginate($this->getFilteredTableQuery(), $this->inventoryKind, $this->getPage($this->getTablePaginationPageName()), $this->getTablePaginationPageName());
    }

    public function showProductRecords(int $product, int $location): void
    {
        $this->tableFilters['product']['value'] = $product;
        $this->tableFilters['location_id']['value'] = $location;
        $this->updatedTableFilters();
        $this->switchStockView('records');
    }

    /** @return array<string, mixed> */
    public function inventoryOverview(): array
    {
        $workspace = app(InventoryWorkspace::class);

        $matrix = $this->inventoryKind === 'allocations' ? $workspace->matrix(auth()->user(), $this->tableFilters ?? []) : null;
        $cards = $matrix && $matrix['authorized']
            ? $workspace->cards($workspace->catalog($workspace->records(auth()->user(), InventoryPermissions::ALLOCATIONS_VIEW, 'allocations'), $this->tableFilters ?? []), 'balances')
            : $workspace->cards($this->getFilteredTableQuery(), $this->inventoryKind);

        return ['kind' => $this->inventoryKind, 'cards' => $cards, 'matrix' => $matrix];
    }

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...parent::getPageClasses(), 'fox-inventory-page'];
    }

    public function removeTableFilter(string $filterName, ?string $field = null, bool $isRemovingAllFilters = false): void
    {
        $children = match ($filterName) {
            'location_id' => ['brand', 'product', 'product_id', 'color', 'variant'],
            'brand' => ['product', 'product_id', 'color', 'variant'],
            'product', 'product_id' => ['color', 'variant'],
            default => [],
        };
        foreach ($children as $child) {
            foreach (['tableFilters', 'tableDeferredFilters'] as $filtersProperty) {
                if (isset($this->{$filtersProperty}[$child])) {
                    $this->{$filtersProperty}[$child]['value'] = null;
                }
            }
        }
        parent::removeTableFilter($filterName, $field, $isRemovingAllFilters);
    }
}
