<?php

namespace App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Pages;

use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseReceipts extends ListRecords
{
    protected static string $resource = PurchaseReceiptResource::class;
}
