<?php

namespace App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages;

use App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSizeAllocations extends ListRecords
{
    protected static string $resource = SizeAllocationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Allocate Sizes'))];
    }
}
