<?php

namespace App\Modules\POS\Filament\Resources\PosDevicePaymentMethods\Pages;

use App\Modules\POS\Filament\Resources\PosDevicePaymentMethods\PosDevicePaymentMethodResource;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Services\PosDeviceManagement;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePosDevicePaymentMethod extends CreateRecord
{
    protected static string $resource = PosDevicePaymentMethodResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return app(PosDeviceManagement::class)->configurePaymentMethods(
            auth()->user(),
            PosDevice::findOrFail($data['pos_device_id']),
            $data['payment_methods'] ?? [],
        );
    }
}
