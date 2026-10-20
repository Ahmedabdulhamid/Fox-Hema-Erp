<?php

namespace App\Modules\Products\Filament\Resources\Sizes\Pages;

use App\Modules\Products\Filament\Resources\Sizes\SizeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSize extends ViewRecord
{
    protected static string $resource = SizeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
