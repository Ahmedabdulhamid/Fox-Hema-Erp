<?php

namespace App\Modules\Sales\Filament\Resources\SalesInvoices\Pages;

use App\Modules\Sales\Filament\Resources\SalesInvoices\SalesInvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSalesInvoices extends ListRecords
{
    protected static string $resource = SalesInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
