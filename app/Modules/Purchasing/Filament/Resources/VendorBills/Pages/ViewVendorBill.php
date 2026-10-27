<?php

namespace App\Modules\Purchasing\Filament\Resources\VendorBills\Pages;

use App\Modules\Purchasing\Filament\Resources\VendorBills\VendorBillResource;
use App\Modules\Purchasing\Filament\SettlementActions;
use App\Modules\Purchasing\Filament\VendorBillActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewVendorBill extends ViewRecord
{
    protected static string $resource = VendorBillResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), ...VendorBillResource::actions(), ...SettlementActions::bill($this->record), VendorBillActions::download('ar'), VendorBillActions::download('en')];
    }
}
