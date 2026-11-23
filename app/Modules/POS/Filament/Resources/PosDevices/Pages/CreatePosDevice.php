<?php

namespace App\Modules\POS\Filament\Resources\PosDevices\Pages;

use App\Modules\POS\Filament\Resources\PosDevices\PosDeviceResource;
use App\Modules\POS\Services\PosDeviceManagement;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePosDevice extends CreateRecord
{
    protected static string $resource = PosDeviceResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return app(PosDeviceManagement::class)->register(auth()->user(), $data);
    }
}
