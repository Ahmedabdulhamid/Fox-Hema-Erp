<?php

namespace App\Modules\Taxes\Filament\Resources\Taxes\Pages;

use App\Modules\Taxes\Filament\Resources\Taxes\TaxResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTax extends ViewRecord
{
    protected static string $resource = TaxResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
