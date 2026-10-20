<?php

namespace App\Modules\AccessManagement\Filament\Resources\Modules\Pages;

use App\Modules\AccessManagement\Filament\Resources\Modules\ModuleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewModule extends ViewRecord
{
    protected static string $resource = ModuleResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
