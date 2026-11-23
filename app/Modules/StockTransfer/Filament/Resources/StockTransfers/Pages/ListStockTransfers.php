<?php

namespace App\Modules\StockTransfer\Filament\Resources\StockTransfers\Pages;

use App\Modules\StockTransfer\Filament\Resources\StockTransfers\StockTransferResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockTransfers extends ListRecords
{
    protected static string $resource = StockTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
