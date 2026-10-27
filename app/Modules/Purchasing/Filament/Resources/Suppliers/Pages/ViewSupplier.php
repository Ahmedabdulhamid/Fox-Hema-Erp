<?php

namespace App\Modules\Purchasing\Filament\Resources\Suppliers\Pages;

use App\Modules\Purchasing\Filament\Resources\Suppliers\SupplierResource;
use App\Modules\Purchasing\Filament\Resources\Suppliers\Tables\SuppliersTable;
use App\Modules\Purchasing\Filament\Resources\VendorPayments\VendorPaymentResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSupplier extends ViewRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('finance')->label(__('Supplier financial overview'))->visible(fn (): bool => VendorPaymentResource::canViewAny())
                ->url(fn (): string => SupplierResource::getUrl('finance', ['record' => $this->record])),
            ...SuppliersTable::statusActions(),
        ];
    }
}
