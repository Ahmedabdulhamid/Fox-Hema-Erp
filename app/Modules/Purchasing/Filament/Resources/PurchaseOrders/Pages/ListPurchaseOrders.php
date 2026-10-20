<?php

namespace App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages;

use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseOrders extends ListRecords
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make(), Action::make('priceHistory')->label(__('Supplier purchase-price history'))->url(PurchaseOrderResource::getUrl('history'))];
    }
}
