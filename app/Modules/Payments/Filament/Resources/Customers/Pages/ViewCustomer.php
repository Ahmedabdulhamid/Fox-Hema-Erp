<?php

namespace App\Modules\Payments\Filament\Resources\Customers\Pages;

use App\Modules\Payments\Filament\CustomerSettlementActions;
use App\Modules\Payments\Filament\Pages\CustomerPaymentHistory;
use App\Modules\Payments\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), CustomerSettlementActions::linkInvoice(), Action::make('statement')->label(__('Customer statement'))->url(fn () => CustomerPaymentHistory::getUrl(['customer' => $this->record->id]))];
    }
}
