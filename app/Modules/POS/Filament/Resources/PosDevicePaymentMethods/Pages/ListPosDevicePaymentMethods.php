<?php

namespace App\Modules\POS\Filament\Resources\PosDevicePaymentMethods\Pages;

use App\Modules\POS\Filament\Resources\PosDevicePaymentMethods\PosDevicePaymentMethodResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPosDevicePaymentMethods extends ListRecords
{
    protected static string $resource = PosDevicePaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->visible(fn (): bool => PosDevicePaymentMethodResource::canCreate())];
    }
}
