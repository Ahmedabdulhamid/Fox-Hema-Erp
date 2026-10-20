<?php

namespace App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Pages;

use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Tables\PurchaseReceiptsTable;
use App\Modules\Purchasing\Filament\Resources\VendorBills\VendorBillResource;
use App\Modules\Purchasing\Models\VendorBill;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

class ViewPurchaseReceipt extends ViewRecord
{
    protected static string $resource = PurchaseReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), ...PurchaseReceiptsTable::actions(),
            Action::make('createBill')->label(__('Create Bill'))
                ->visible(fn (): bool => $this->record->status === 'posted'
                    && Gate::allows('create', VendorBill::class))
                ->url(fn (): string => VendorBillResource::getUrl('create', ['receipt' => $this->record->uuid])),
        ];
    }
}
