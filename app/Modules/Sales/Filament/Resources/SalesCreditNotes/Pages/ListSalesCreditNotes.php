<?php

namespace App\Modules\Sales\Filament\Resources\SalesCreditNotes\Pages;

use App\Modules\Sales\Filament\Resources\SalesCreditNotes\SalesCreditNoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSalesCreditNotes extends ListRecords
{
    protected static string $resource = SalesCreditNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
