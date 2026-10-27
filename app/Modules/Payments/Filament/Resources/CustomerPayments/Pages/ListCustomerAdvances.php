<?php

namespace App\Modules\Payments\Filament\Resources\CustomerPayments\Pages;

use App\Modules\Payments\Filament\Resources\CustomerPayments\CustomerPaymentResource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListCustomerAdvances extends ListCustomerPayments
{
    public function getTitle(): string
    {
        return __('Customer advances');
    }

    public function table(Table $table): Table
    {
        return CustomerPaymentResource::table($table)->modifyQueryUsing(fn (Builder $query) => $query->where('payments.status', 'posted')
            ->whereRaw("payments.amount > (SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a WHERE a.payment_id=payments.id AND a.released_at IS NULL) + (SELECT COALESCE(SUM(r.amount),0) FROM payment_refunds r WHERE r.payment_id=payments.id AND r.status='posted' AND r.recovered_at IS NULL)"));
    }
}
