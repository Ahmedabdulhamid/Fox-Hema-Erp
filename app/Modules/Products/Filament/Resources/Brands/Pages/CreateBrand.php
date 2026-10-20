<?php

namespace App\Modules\Products\Filament\Resources\Brands\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Products\Filament\Resources\Brands\BrandResource;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Services\ProductCatalogManager;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBrand extends CreateRecord
{
    protected static string $resource = BrandResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return ProductMutation::run(fn () => app(ProductCatalogManager::class)->save(auth()->user(), Brand::class, $data), 'data');
    }
}
