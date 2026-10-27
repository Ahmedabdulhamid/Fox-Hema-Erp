<?php

namespace App\Modules\Payments\Filament\Resources\PaymentMethods\Pages;

use App\Modules\Payments\Filament\Resources\PaymentMethods\PaymentMethodResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPaymentMethod extends ViewRecord
{
    protected static string $resource = PaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->label(__('payment_methods.actions.edit')), PaymentMethodResource::statusAction(true), PaymentMethodResource::statusAction(false)];
    }
}
