<?php

namespace App\Modules\Discounts\Filament\Resources\Discounts\Pages;

use App\Modules\Discounts\Filament\Resources\Discounts\DiscountResource;
use App\Modules\Discounts\Services\DiscountManager;
use App\Modules\Products\Filament\ProductMutation;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDiscount extends CreateRecord
{
    protected static string $resource = DiscountResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return ProductMutation::run(fn () => app(DiscountManager::class)->save(auth()->user(), $data), 'data');
    }
}
