<?php

namespace App\Modules\POS\Filament\Resources\PosDevicePaymentMethods\Pages;

use App\Modules\POS\Filament\Resources\PosDevicePaymentMethods\PosDevicePaymentMethodResource;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosDevicePaymentMethod;
use App\Modules\POS\Services\PosDeviceManagement;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditPosDevicePaymentMethod extends EditRecord
{
    protected static string $resource = PosDevicePaymentMethodResource::class;

    public function getTitle(): string
    {
        return __('pos.edit_device_payment_methods_title', [
            'device' => $this->record->posDevice->name,
        ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['pos_device_id'] = $this->record->pos_device_id;
        $data['payment_methods'] = PosDevicePaymentMethod::query()
            ->where('pos_device_id', $this->record->pos_device_id)
            ->with(['paymentMethod:id,type,is_active,available_in_pos'])
            ->orderBy('payment_method_id')
            ->get()
            ->map(fn (PosDevicePaymentMethod $configuration): array => [
                'payment_method_id' => (int) $configuration->payment_method_id,
                'treasury_account_id' => $configuration->paymentMethod->type === 'cash'
                    ? (int) $configuration->treasury_account_id
                    : null,
                'is_active' => (bool) $configuration->is_active
                    && $configuration->paymentMethod->is_active
                    && $configuration->paymentMethod->available_in_pos,
            ])
            ->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PosDevicePaymentMethod $record */
        try {
            return app(PosDeviceManagement::class)->configurePaymentMethods(
                auth()->user(),
                PosDevice::findOrFail($record->pos_device_id),
                $data['payment_methods'] ?? [],
            );
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('pos.notifications.payment_configuration_failed'))
                ->body(collect($exception->errors())->flatten()->implode(' '))
                ->danger()
                ->send();

            throw $exception;
        }
    }
}
