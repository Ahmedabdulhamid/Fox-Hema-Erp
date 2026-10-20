<?php

namespace App\Modules\AccessManagement\Filament\Resources\Locations\Pages;

use App\Modules\AccessManagement\Filament\Resources\Locations\LocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLocations extends ListRecords
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
