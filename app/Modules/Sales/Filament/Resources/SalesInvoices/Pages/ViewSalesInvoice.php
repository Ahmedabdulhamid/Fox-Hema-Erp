<?php

namespace App\Modules\Sales\Filament\Resources\SalesInvoices\Pages;

use App\Modules\Sales\Filament\ReportsSalesValidationErrors;
use App\Modules\Sales\Filament\Resources\SalesInvoices\SalesInvoiceResource;
use App\Modules\Sales\Filament\SalesInvoiceActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSalesInvoice extends ViewRecord
{
    use ReportsSalesValidationErrors;

    protected static string $resource = SalesInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), SalesInvoiceActions::post(), SalesInvoiceActions::cancel(),
            SalesInvoiceActions::download('ar'), SalesInvoiceActions::download('en')];
    }
}
