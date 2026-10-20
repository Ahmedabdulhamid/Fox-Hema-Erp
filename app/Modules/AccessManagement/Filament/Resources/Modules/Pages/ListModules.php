<?php

namespace App\Modules\AccessManagement\Filament\Resources\Modules\Pages;

use App\Modules\AccessManagement\Filament\Resources\Modules\ModuleResource;
use Filament\Resources\Pages\ListRecords;

class ListModules extends ListRecords
{
    protected static string $resource = ModuleResource::class;
}
