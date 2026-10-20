<?php

namespace App\Modules\Purchasing\Filament\Resources\Suppliers\Pages;

use App\Modules\Purchasing\Filament\Resources\Suppliers\SupplierResource;
use App\Modules\Purchasing\Filament\Resources\Suppliers\Tables\SuppliersTable;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSupplier extends ViewRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ...SuppliersTable::statusActions(),
        ];
    }
}
