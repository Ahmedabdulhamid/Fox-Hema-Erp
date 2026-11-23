<?php

namespace App\Modules\POS\Observers;

use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\POS\Models\PosDevice;
use Illuminate\Validation\ValidationException;

class PosDeviceObserver
{
    public function creating(PosDevice $device): void
    {
        $device->device_code = app(DocumentNumberService::class)->allocate(
            (int) $device->location_id,
            'POS',
            $device->uuid,
        );
    }

    public function updating(PosDevice $device): void
    {
        if ($device->isDirty('device_code')) {
            throw ValidationException::withMessages(['device_code' => __('Generated codes cannot be changed.')]);
        }
    }
}
