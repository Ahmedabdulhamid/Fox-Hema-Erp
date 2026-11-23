<?php

namespace App\Modules\POS\Filament\Resources\PosDevices\Pages;

use App\Modules\POS\Filament\Resources\PosDevices\PosDeviceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPosDevices extends ListRecords
{
    protected static string $resource = PosDeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->visible(fn (): bool => PosDeviceResource::canCreate())];
    }
}
