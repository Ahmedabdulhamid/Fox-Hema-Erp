<?php

namespace App\Modules\Products\Filament\Resources\Sizes\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Products\Filament\Resources\Sizes\SizeResource;
use App\Modules\Products\Models\Size;
use App\Modules\Products\Services\ProductCatalogManager;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSize extends CreateRecord
{
    protected static string $resource = SizeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return ProductMutation::run(fn () => app(ProductCatalogManager::class)->save(auth()->user(), Size::class, $data), 'data');
    }
}
