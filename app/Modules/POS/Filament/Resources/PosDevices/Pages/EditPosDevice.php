<?php

namespace App\Modules\POS\Filament\Resources\PosDevices\Pages;

use App\Modules\POS\Filament\Resources\PosDevices\PosDeviceResource;
use App\Modules\POS\Services\PosDeviceManagement;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPosDevice extends EditRecord
{
    protected static string $resource = PosDeviceResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(PosDeviceManagement::class)->update(auth()->user(), $record, $data);
    }
}
