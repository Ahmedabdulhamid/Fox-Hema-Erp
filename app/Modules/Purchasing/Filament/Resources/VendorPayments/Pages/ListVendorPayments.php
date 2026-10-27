<?php

namespace App\Modules\Purchasing\Filament\Resources\VendorPayments\Pages;

use App\Modules\Purchasing\Filament\Resources\VendorPayments\VendorPaymentResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVendorPayments extends ListRecords
{
    protected static string $resource = VendorPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make(), Action::make('advances')->label(__('Supplier advances'))->url(VendorPaymentResource::getUrl('advances'))];
    }
}
