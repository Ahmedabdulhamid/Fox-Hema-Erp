<?php

namespace App\Modules\Products\Filament\Resources\Sizes\Pages;

use App\Modules\Products\Filament\Resources\Sizes\SizeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSizes extends ListRecords
{
    protected static string $resource = SizeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
