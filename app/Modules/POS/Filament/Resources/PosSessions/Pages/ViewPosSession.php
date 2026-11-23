<?php

namespace App\Modules\POS\Filament\Resources\PosSessions\Pages;

use App\Modules\POS\Filament\Resources\PosSessions\PosSessionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPosSession extends ViewRecord
{
    protected static string $resource = PosSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PosSessionResource::cashMovementAction(),
            PosSessionResource::closeAction(),
            PosSessionResource::approveAction(),
        ];
    }
}
