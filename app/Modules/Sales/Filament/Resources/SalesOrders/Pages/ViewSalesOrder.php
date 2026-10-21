<?php

namespace App\Modules\Sales\Filament\Resources\SalesOrders\Pages;

use App\Modules\Sales\Filament\ReportsSalesValidationErrors;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\SalesDeliveryResource;
use App\Modules\Sales\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Modules\Sales\Filament\SalesOrderActions;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesFulfillmentService;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSalesOrder extends ViewRecord
{
    use ReportsSalesValidationErrors;

    protected static string $resource = SalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), ...SalesOrderActions::make(),
            Action::make('createDelivery')->label(__('Create delivery draft'))
                ->visible(fn (): bool => SalesDeliveryResource::canCreate()
                    && app(SalesDeliveryService::class)->eligibleOrders(auth()->user())->whereKey($this->record->id)->exists()
                    && collect(app(SalesFulfillmentService::class)->quantities($this->record))
                        ->contains(fn ($line): bool => BigDecimal::of($line['remaining'])->isPositive()))
                ->url(fn (): string => SalesDeliveryResource::getUrl('create', ['sales_order' => $this->record->uuid]))];
    }
}
