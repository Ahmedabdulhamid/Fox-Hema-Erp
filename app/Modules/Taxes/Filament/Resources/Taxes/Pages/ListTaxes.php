<?php

namespace App\Modules\Taxes\Filament\Resources\Taxes\Pages;

use App\Modules\Taxes\Filament\Resources\Taxes\TaxResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTaxes extends ListRecords
{
    protected static string $resource = TaxResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
