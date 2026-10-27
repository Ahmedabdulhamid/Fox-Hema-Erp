<?php

namespace App\Modules\Payments\Filament\Resources\CustomerRefunds\Pages;

use App\Modules\Payments\Filament\Resources\CustomerRefunds\CustomerRefundResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomerRefunds extends ListRecords
{
    protected static string $resource = CustomerRefundResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
