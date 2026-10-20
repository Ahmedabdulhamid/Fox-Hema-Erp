<?php

namespace App\Modules\Inventory\Filament\Resources\StockBalances\Pages;

use App\Modules\Inventory\Filament\Resources\StockBalances\StockBalanceResource;
use Filament\Resources\Pages\ListRecords;

class ListStockBalances extends ListRecords
{
    protected static string $resource = StockBalanceResource::class;

    public function getSubheading(): ?string
    {
        return __('Only recorded stock balances are shown. No balance record does not confirm a physical stock count.');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
