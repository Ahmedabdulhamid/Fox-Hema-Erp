<?php

namespace App\Modules\Payments\Filament\Resources\PaymentAllocations;

use App\Modules\Payments\Filament\CustomerSettlementActions;
use App\Modules\Payments\Filament\Resources\CustomerPayments\CustomerPaymentResource;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Modules\Products\Filament\MasterDetail;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentAllocationResource extends Resource
{
    protected static bool $isDiscovered = false;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $model = PaymentAllocation::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('Payments');
    }

    public static function getModelLabel(): string
    {
        return __('Payment allocation');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Payment allocations');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('payment_id', CustomerPaymentResource::getEloquentQuery()->select('payments.id'))->with(['payment.customer', 'invoice']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('code')->label(__('payments.fields.code'))->searchable(), TextColumn::make('payment.payment_number')->label(__('payments.fields.payment_number'))->searchable(), TextColumn::make('payment.customer.name')->label(__('payments.fields.customer_id'))->searchable(),
            TextColumn::make('invoice.invoice_number')->label(__('payments.fields.sales_invoice_id'))->searchable(), TextColumn::make('amount')->label(__('payments.fields.amount')), TextColumn::make('allocated_at')->label(__('payments.fields.allocated_at'))->dateTime(), TextColumn::make('released_at')->toggleable(isToggledHiddenByDefault: true)->label(__('payments.fields.released_at'))->dateTime(), TextColumn::make('release_reason')->toggleable(isToggledHiddenByDefault: true)->label(__('payments.fields.release_reason'))->wrap()])
            ->filters([Filter::make('active')->label(__('payments.fields.active'))->query(fn (Builder $q) => $q->whereNull('released_at'))])->recordActions([MasterDetail::action(fn (): array => [Section::make(__('Payment allocation'))->columns(['default' => 1, 'sm' => 2])->schema([
                TextEntry::make('code')->label(__('payments.fields.code')),
                TextEntry::make('payment.payment_number')->label(__('payments.fields.payment_number')),
                TextEntry::make('payment.customer.name')->label(__('payments.fields.customer_id')),
                TextEntry::make('invoice.invoice_number')->label(__('payments.fields.sales_invoice_id')),
                TextEntry::make('amount')->label(__('payments.fields.amount')),
                TextEntry::make('allocated_at')->label(__('payments.fields.allocated_at'))->dateTime(),
                TextEntry::make('released_at')->label(__('payments.fields.released_at'))->dateTime(),
                TextEntry::make('release_reason')->label(__('payments.fields.release_reason'))->columnSpanFull(),
            ])]), CustomerSettlementActions::release()])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPaymentAllocations::route('/')];
    }
}
