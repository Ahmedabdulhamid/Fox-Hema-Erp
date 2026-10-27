<?php

namespace App\Modules\Payments\Filament\Resources\CustomerRefunds;

use App\Modules\Payments\Filament\Resources\CustomerPayments\CustomerPaymentResource;
use App\Modules\Payments\Models\CustomerInvoiceAccount;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Products\Filament\MasterDetail;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Services\SalesPermissions;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomerRefundResource extends Resource
{
    protected static bool $isDiscovered = false;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $model = PaymentRefund::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $recordTitleAttribute = 'refund_number';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('Payments');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Customer refunds');
    }

    public static function getModelLabel(): string
    {
        return __('Customer refund');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('payment_id', CustomerPaymentResource::getEloquentQuery()->select('payments.id'))->with(['payment.customer', 'creditNote']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('payment_id')->label(__('Original customer receipt'))->options(fn () => CustomerPaymentResource::getEloquentQuery()->where('status', 'posted')->pluck('payment_number', 'id'))->searchable()->required()->live(),
            Select::make('sales_credit_note_id')->label(__('Credit note'))->options(function (Get $get): array {
                $payment = CustomerPaymentResource::getEloquentQuery()->find($get('payment_id'));
                if ($payment === null) {
                    return [];
                }
                $query = SalesCreditNote::query()->where('location_id', $payment->location_id)->where('status', 'posted')->whereNull('cancelled_at')
                    ->whereIn('sales_invoice_id', CustomerInvoiceAccount::where('customer_id', $payment->customer_id)->select('sales_invoice_id'));
                if (! auth()->user()->can(SalesPermissions::CREDITS_VIEW)) {
                    return [];
                }

                return $query->pluck('credit_note_number', 'id')->all();
            })->searchable(),
            DatePicker::make('refund_date')->label(__('payments.fields.refund_date'))->default(now()->toDateString())->maxDate(now())->required(),
            TextInput::make('amount')->label(__('payments.fields.amount'))->inputMode('decimal')->suffix(__('payments.currency'))->required(),
            TextInput::make('external_reference')->label(__('payments.fields.external_reference'))->maxLength(191), Textarea::make('reason')->required()->maxLength(16000),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('refund_number')->label(__('payments.fields.refund_number')), TextEntry::make('payment.payment_number')->label(__('payments.fields.payment_number')), TextEntry::make('payment.customer.name')->label(__('payments.fields.customer_id')), TextEntry::make('creditNote.credit_note_number')->label(__('payments.fields.sales_credit_note_id')),
            TextEntry::make('status')->label(__('payments.fields.status'))->badge()->formatStateUsing(fn (string $state): string => __('payments.status.'.$state)), TextEntry::make('amount')->label(__('payments.fields.amount')), TextEntry::make('refund_date')->label(__('payments.fields.refund_date'))->date(), TextEntry::make('external_reference')->label(__('payments.fields.external_reference')),
            TextEntry::make('recovered_at')->label(__('payments.fields.recovered_at'))->dateTime(), TextEntry::make('recovery_reference')->label(__('payments.fields.recovery_reference')), TextEntry::make('recovery_reason')->label(__('payments.fields.recovery_reason')), TextEntry::make('reason')->label(__('payments.fields.reason')), TextEntry::make('posted_at')->label(__('payments.fields.posted_at'))->dateTime()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('refund_number')->label(__('payments.fields.refund_number'))->searchable(), TextColumn::make('payment.payment_number')->toggleable(isToggledHiddenByDefault: true)->label(__('payments.fields.payment_number'))->searchable(), TextColumn::make('payment.customer.name')->label(__('payments.fields.customer_id'))->searchable(),
            TextColumn::make('amount')->label(__('payments.fields.amount')), TextColumn::make('refund_date')->label(__('payments.fields.refund_date'))->date()->sortable(), TextColumn::make('external_reference')->toggleable(isToggledHiddenByDefault: true)->label(__('payments.fields.external_reference'))->searchable(), TextColumn::make('status')->label(__('payments.fields.status'))->badge()->formatStateUsing(fn (string $state): string => __('payments.status.'.$state))])
            ->filters([SelectFilter::make('status')->label(__('payments.fields.status'))->options(['draft' => __('Draft'), 'posted' => __('Posted')])])->recordActions([MasterDetail::action(fn (Schema $schema): Schema => self::infolist($schema))->authorize('view'), ViewAction::make()])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCustomerRefunds::route('/'), 'create' => Pages\CreateCustomerRefund::route('/create'), 'view' => Pages\ViewCustomerRefund::route('/{record}')];
    }
}
