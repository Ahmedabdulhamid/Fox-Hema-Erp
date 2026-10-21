<?php

namespace App\Modules\Sales\Filament\Resources\SalesOrders\Pages;

use App\Modules\Sales\Filament\ReportsSalesValidationErrors;
use App\Modules\Sales\Filament\Resources\SalesOrders\SalesOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSalesOrders extends ListRecords
{
    use ReportsSalesValidationErrors;

    protected static string $resource = SalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
