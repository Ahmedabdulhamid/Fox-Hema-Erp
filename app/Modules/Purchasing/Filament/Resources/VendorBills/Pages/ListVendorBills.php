<?php

namespace App\Modules\Purchasing\Filament\Resources\VendorBills\Pages;

use App\Modules\Purchasing\Filament\Resources\VendorBills\VendorBillResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVendorBills extends ListRecords
{
    protected static string $resource = VendorBillResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
