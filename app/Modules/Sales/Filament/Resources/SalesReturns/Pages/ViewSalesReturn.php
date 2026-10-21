<?php

namespace App\Modules\Sales\Filament\Resources\SalesReturns\Pages;

use App\Modules\Sales\Filament\ReportsSalesValidationErrors;
use App\Modules\Sales\Filament\Resources\SalesReturns\SalesReturnResource;
use App\Modules\Sales\Filament\SalesReturnActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSalesReturn extends ViewRecord
{
    use ReportsSalesValidationErrors;

    protected static string $resource = SalesReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), SalesReturnActions::approve(), SalesReturnActions::post(), SalesReturnActions::cancel()];
    }
}
