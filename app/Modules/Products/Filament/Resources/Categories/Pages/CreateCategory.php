<?php

namespace App\Modules\Products\Filament\Resources\Categories\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Products\Filament\Resources\Categories\CategoryResource;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Services\ProductCatalogManager;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return ProductMutation::run(fn () => app(ProductCatalogManager::class)->save(auth()->user(), Category::class, $data), 'data');
    }
}
