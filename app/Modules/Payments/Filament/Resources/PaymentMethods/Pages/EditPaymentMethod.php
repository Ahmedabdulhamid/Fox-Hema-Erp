<?php

namespace App\Modules\Payments\Filament\Resources\PaymentMethods\Pages;

use App\Modules\Payments\Filament\PaymentMethodMutation;
use App\Modules\Payments\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Modules\Payments\Services\PaymentMethods;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPaymentMethod extends EditRecord
{
    protected static string $resource = PaymentMethodResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return PaymentMethodMutation::run(fn () => app(PaymentMethods::class)->save(auth()->user(), $data, $record), 'data');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('payment_methods.notifications.updated');
    }
}
