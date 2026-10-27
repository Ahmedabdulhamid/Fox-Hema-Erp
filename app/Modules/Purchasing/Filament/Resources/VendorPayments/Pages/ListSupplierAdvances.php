<?php

namespace App\Modules\Purchasing\Filament\Resources\VendorPayments\Pages;

use App\Modules\Purchasing\Filament\Resources\VendorPayments\VendorPaymentResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListSupplierAdvances extends ListVendorPayments
{
    public function getTitle(): string
    {
        return __('Supplier advances');
    }

    public function table(Table $table): Table
    {
        return VendorPaymentResource::table($table)->modifyQueryUsing(fn (Builder $query) => $query
            ->where('vendor_payments.status', 'posted')->whereNull('vendor_payments.voided_at')
            ->whereNotNull('vendor_payments.idempotency_key')->whereNotNull('vendor_payments.payload_fingerprint')
            ->whereNotNull('vendor_payments.payment_method_id')->where('vendor_payments.currency', 'EGP')
            ->whereRaw('vendor_payments.amount > (SELECT COALESCE(SUM(a.amount), 0) FROM vendor_payment_allocations a WHERE a.vendor_payment_id = vendor_payments.id AND a.released_at IS NULL)'));
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Create supplier advance')),
            Action::make('payments')->label(__('Supplier payments'))->url(VendorPaymentResource::getUrl())];
    }
}
