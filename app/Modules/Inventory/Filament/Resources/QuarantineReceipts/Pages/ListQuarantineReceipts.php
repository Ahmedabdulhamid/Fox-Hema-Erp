<?php

namespace App\Modules\Inventory\Filament\Resources\QuarantineReceipts\Pages;

use App\Modules\Inventory\Filament\InventoryListRecords;
use App\Modules\Inventory\Filament\Resources\QuarantineReceipts\QuarantineReceiptResource;

class ListQuarantineReceipts extends InventoryListRecords
{
    protected string $inventoryKind = 'quarantine';

    public function getSubheading(): ?string
    {
        return __('Returned stock held separately. Inspection observations do not release stock.');
    }

    protected static string $resource = QuarantineReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
