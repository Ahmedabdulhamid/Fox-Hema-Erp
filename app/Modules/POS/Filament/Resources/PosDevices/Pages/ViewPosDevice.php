<?php

namespace App\Modules\POS\Filament\Resources\PosDevices\Pages;

use App\Modules\POS\Filament\Resources\PosDevicePaymentMethods\PosDevicePaymentMethodResource;
use App\Modules\POS\Filament\Resources\PosDevices\PosDeviceResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPosDevice extends ViewRecord
{
    protected static string $resource = PosDeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('paymentMethods')
                ->label(__('pos.actions.payment_methods'))
                ->url(fn (): string => PosDevicePaymentMethodResource::getUrl('index', [
                    'tableFilters' => ['pos_device_id' => ['value' => $this->record->id]],
                ])),
        ];
    }
}
