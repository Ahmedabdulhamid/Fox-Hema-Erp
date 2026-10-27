<?php

namespace App\Modules\Payments\Filament\Resources\Customers\Pages;

use App\Modules\Payments\Filament\CustomerPaymentMutation;
use App\Modules\Payments\Filament\Resources\Customers\CustomerResource;
use App\Modules\Payments\Services\CustomerAccounts;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        unset($data['location_id']);

        return CustomerPaymentMutation::run(fn () => app(CustomerAccounts::class)->save(auth()->user(), $data, $record), 'data');
    }
}
