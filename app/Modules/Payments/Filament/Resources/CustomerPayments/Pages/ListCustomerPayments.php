<?php

namespace App\Modules\Payments\Filament\Resources\CustomerPayments\Pages;

use App\Modules\Payments\Filament\Resources\CustomerPayments\CustomerPaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerPayments extends ListRecords
{
    protected static string $resource = CustomerPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
