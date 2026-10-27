<?php

namespace App\Modules\Inventory\Filament\Resources\StockBalances\Pages;

use App\Modules\Inventory\Filament\InventoryListRecords;
use App\Modules\Inventory\Filament\Resources\StockBalances\StockBalanceResource;

class ListStockBalances extends InventoryListRecords
{
    protected static string $resource = StockBalanceResource::class;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...parent::getPageClasses(), 'fox-stock-balances-page'];
    }

    public function getSubheading(): ?string
    {
        return __('Only recorded stock balances are shown. No balance record does not confirm a physical stock count.');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
