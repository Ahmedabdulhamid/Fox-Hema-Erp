<?php

namespace App\Modules\Purchasing\Filament\Resources\VendorBills\Pages;

use App\Modules\Purchasing\Filament\Resources\VendorBills\VendorBillResource;
use App\Modules\Purchasing\Filament\SettlementActions;
use App\Modules\Purchasing\Filament\VendorBillActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewVendorBill extends ViewRecord
{
    protected static string $resource = VendorBillResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->loadMissing([
            'items.receiptMatches.purchaseReceiptItem.purchaseReceipt',
            'items.receiptMatches.purchaseReceiptItem.purchaseOrderItem',
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), ...VendorBillResource::actions(), ...SettlementActions::bill($this->record), VendorBillActions::download('ar'), VendorBillActions::download('en')];
    }
}
