<?php

namespace App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages;

use App\Modules\Sales\Filament\Resources\SalesDeliveries\SalesDeliveryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSalesDeliveries extends ListRecords
{
    protected static string $resource = SalesDeliveryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
