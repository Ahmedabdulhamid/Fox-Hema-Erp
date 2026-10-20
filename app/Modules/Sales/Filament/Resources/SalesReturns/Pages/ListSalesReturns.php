<?php

namespace App\Modules\Sales\Filament\Resources\SalesReturns\Pages;

use App\Modules\Sales\Filament\Resources\SalesReturns\SalesReturnResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSalesReturns extends ListRecords
{
    protected static string $resource = SalesReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
