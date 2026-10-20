<?php

namespace App\Modules\Inventory\Filament\Resources\QuarantineReceipts\Pages;

use App\Modules\Inventory\Filament\Resources\QuarantineReceipts\QuarantineReceiptResource;
use Filament\Resources\Pages\ListRecords;

class ListQuarantineReceipts extends ListRecords
{
    protected static string $resource = QuarantineReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
