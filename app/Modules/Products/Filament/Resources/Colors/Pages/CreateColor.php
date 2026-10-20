<?php

namespace App\Modules\Products\Filament\Resources\Colors\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Products\Filament\Resources\Colors\ColorResource;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Services\ProductCatalogManager;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateColor extends CreateRecord
{
    protected static string $resource = ColorResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return ProductMutation::run(fn () => app(ProductCatalogManager::class)->save(auth()->user(), Color::class, $data), 'data');
    }
}
