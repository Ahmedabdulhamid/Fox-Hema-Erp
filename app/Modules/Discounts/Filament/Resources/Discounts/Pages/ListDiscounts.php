<?php

namespace App\Modules\Discounts\Filament\Resources\Discounts\Pages;

use App\Modules\Discounts\Filament\Resources\Discounts\DiscountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDiscounts extends ListRecords
{
    protected static string $resource = DiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
