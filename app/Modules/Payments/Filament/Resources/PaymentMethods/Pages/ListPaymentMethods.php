<?php

namespace App\Modules\Payments\Filament\Resources\PaymentMethods\Pages;

use App\Modules\Payments\Filament\Resources\PaymentMethods\PaymentMethodResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaymentMethods extends ListRecords
{
    protected static string $resource = PaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('payment_methods.actions.create'))];
    }
}
