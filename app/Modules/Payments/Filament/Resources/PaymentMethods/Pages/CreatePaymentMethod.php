<?php

namespace App\Modules\Payments\Filament\Resources\PaymentMethods\Pages;

use App\Modules\Payments\Filament\PaymentMethodMutation;
use App\Modules\Payments\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Modules\Payments\Services\PaymentMethods;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePaymentMethod extends CreateRecord
{
    protected static string $resource = PaymentMethodResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return PaymentMethodMutation::run(fn () => app(PaymentMethods::class)->save(auth()->user(), $data), 'data');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('payment_methods.notifications.created');
    }

    protected function getRedirectUrl(): string
    {
        return PaymentMethodResource::getUrl('view', ['record' => $this->record]);
    }
}
