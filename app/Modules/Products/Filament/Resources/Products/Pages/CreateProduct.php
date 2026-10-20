<?php

namespace App\Modules\Products\Filament\Resources\Products\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Products\Filament\Resources\Products\ProductResource;
use App\Modules\Products\Services\ProductCreationManager;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordCreation(array $data): Model
    {
        return ProductMutation::run(fn () => app(ProductCreationManager::class)->create(auth()->user(), $data), 'data');
    }
}
