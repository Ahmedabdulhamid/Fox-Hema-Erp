<?php

namespace App\Modules\Taxes\Filament\Resources\Taxes\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Taxes\Filament\Resources\Taxes\TaxResource;
use App\Modules\Taxes\Services\TaxManager;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTax extends CreateRecord
{
    protected static string $resource = TaxResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return ProductMutation::run(fn () => app(TaxManager::class)->save(auth()->user(), $data), 'data');
    }
}
