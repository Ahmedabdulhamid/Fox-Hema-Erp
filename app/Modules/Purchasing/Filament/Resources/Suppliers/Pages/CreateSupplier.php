<?php

namespace App\Modules\Purchasing\Filament\Resources\Suppliers\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Purchasing\Filament\Resources\Suppliers\SupplierResource;
use App\Modules\Purchasing\Services\SupplierService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSupplier extends CreateRecord
{
    protected static string $resource = SupplierResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return ProductMutation::run(fn () => app(SupplierService::class)->create(auth()->user(), $data), 'data');
    }
}
