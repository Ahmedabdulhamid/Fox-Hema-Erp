<?php

namespace App\Modules\Payments\Filament\Resources\PaymentAllocations\Pages;

use App\Modules\Payments\Filament\CustomerSettlementActions;
use App\Modules\Payments\Filament\Resources\PaymentAllocations\PaymentAllocationResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentAllocations extends ListRecords
{
    protected static string $resource = PaymentAllocationResource::class;

    protected function getHeaderActions(): array
    {
        return [CustomerSettlementActions::allocate(true)];
    }
}
