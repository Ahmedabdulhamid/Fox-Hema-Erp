<?php

namespace App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages;

use App\Modules\Sales\Filament\ReportsSalesValidationErrors;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\SalesDeliveryResource;
use App\Modules\Sales\Filament\SalesDeliveryActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSalesDelivery extends ViewRecord
{
    use ReportsSalesValidationErrors;

    protected static string $resource = SalesDeliveryResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), SalesDeliveryActions::post(), SalesDeliveryActions::cancel()];
    }
}
