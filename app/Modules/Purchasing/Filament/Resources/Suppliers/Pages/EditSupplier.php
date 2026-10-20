<?php

namespace App\Modules\Purchasing\Filament\Resources\Suppliers\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Purchasing\Filament\Resources\Suppliers\SupplierResource;
use App\Modules\Purchasing\Filament\Resources\Suppliers\Tables\SuppliersTable;
use App\Modules\Purchasing\Services\SupplierService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return ProductMutation::run(fn () => app(SupplierService::class)->update(auth()->user(), $record, $data), 'data');
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            ...SuppliersTable::statusActions(),
        ];
    }
}
