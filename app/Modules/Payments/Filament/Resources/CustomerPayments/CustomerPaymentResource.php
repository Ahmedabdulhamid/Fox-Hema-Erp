<?php

namespace App\Modules\Payments\Filament\Resources\CustomerPayments;

use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Products\Filament\MasterDetail;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryAccess;
use App\Modules\Treasury\Services\TreasuryAccounts;
use App\Modules\Treasury\Services\TreasuryPermissions;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerPaymentResource extends Resource
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $model = Payment::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $recordTitleAttribute = 'payment_number';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('Payments');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Customer payments');
    }

    public static function getModelLabel(): string
    {
        return __('Customer payment');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationItems(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        return app(CustomerAccess::class)->scope(parent::getEloquentQuery(), auth()->user())->where(fn (Builder $query) => $query->whereNotNull('customer_id')->orWhere(fn (Builder $query) => $query->where('source_document_type', 'sales_invoice')->whereNotNull('treasury_account_id')))->with(['customer', 'paymentMethod']);
    }

    public static function customers(): array
    {
        return app(CustomerAccess::class)->scope(Customer::query(), auth()->user())->where('is_active', true)->pluck('name', 'id')->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')->label(__('Customer'))->options(fn () => static::customers())->searchable()->required()->live(),
            DatePicker::make('payment_date')->label(__('payments.fields.payment_date'))->default(now()->toDateString())->maxDate(now())->required(),
            TextInput::make('amount')->label(__('payments.fields.amount'))->suffix(__('payments.currency'))->inputMode('decimal')->required(),
            Select::make('payment_method_id')->label(__('Payment method'))->options(fn () => PaymentMethod::where('is_active', true)->whereIn('type', ['cash', 'bank_transfer'])->pluck('name', 'id'))->required()->live(),
            Select::make('treasury_account_id')->label(__('Receiving Treasury account'))->options(fn (Get $get) => static::accounts($get('customer_id'), $get('payment_method_id')))->searchable()->required(),
            TextInput::make('external_reference')->label(__('payments.fields.external_reference'))->maxLength(191), Textarea::make('notes')->maxLength(16000)]);
    }

    public static function accounts(mixed $customerId, mixed $methodId): array
    {
        $customer = app(CustomerAccess::class)->scope(Customer::query(), auth()->user())->find($customerId);
        if ($customer === null) {
            return [];
        }

        return static::accountsForLocation((int) $customer->location_id, $methodId);
    }

    public static function accountsForLocation(int $location, mixed $methodId): array
    {
        $type = PaymentMethod::whereKey($methodId)->where('is_active', true)->value('type');
        $options = [];
        foreach (app(TreasuryAccounts::class)->visible(auth()->user()) as $account) {
            if (! $account->is_active || (int) $account->location_id !== $location || $account->currency !== 'EGP' ||
            ! (($type === 'cash' && $account->type !== 'bank') || (in_array($type, ['bank_transfer', 'card'], true) && $account->type === 'bank'))) {
                continue;
            }
            try {
                app(TreasuryAccess::class)->operation(auth()->user(), TreasuryPermissions::CREATE, $account->location_id);
                $options[$account->id] = $account->code.' | '.$account->name;
            } catch (AuthorizationException|HttpException) {
                continue;
            }
        }

        return $options;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('payment_number')->label(__('payments.fields.payment_number')), TextEntry::make('customer.name')->label(__('payments.fields.customer_id'))->placeholder(__('payments.walk_in')), TextEntry::make('status')->label(__('payments.fields.status'))->badge()->formatStateUsing(fn (string $state): string => __('payments.status.'.$state)), TextEntry::make('amount')->label(__('payments.fields.amount')),
            TextEntry::make('payment_date')->label(__('payments.fields.payment_date'))->date(), TextEntry::make('paymentMethod.name')->label(__('Method')), TextEntry::make('external_reference')->label(__('payments.fields.external_reference')), TextEntry::make('posted_at')->label(__('payments.fields.posted_at'))->dateTime(),
            TextEntry::make('treasury_account')->label(__('payments.fields.treasury_account'))->state(function (Payment $record): string {
                $account = TreasuryAccount::find($record->treasury_account_id);
                if ($account === null) {
                    return __('Unlinked');
                }
                try {
                    app(TreasuryAccess::class)->authorize(auth()->user(), TreasuryPermissions::VIEW, $account->location_id);

                    return $account->code.' | '.$account->name;
                } catch (AuthorizationException|HttpException) {
                    return __('Treasury viewing permission required');
                }
            }),
            RepeatableEntry::make('allocations')->label(__('payments.fields.allocations'))->columnSpanFull()->schema([TextEntry::make('code')->label(__('payments.fields.code')), TextEntry::make('invoice.invoice_number')->label(__('payments.fields.sales_invoice_id')), TextEntry::make('amount')->label(__('payments.fields.amount')), TextEntry::make('allocated_at')->label(__('payments.fields.allocated_at'))->dateTime(), TextEntry::make('released_at')->label(__('payments.fields.released_at'))->dateTime()]),
            RepeatableEntry::make('refunds')->label(__('payments.fields.refunds'))->columnSpanFull()->schema([TextEntry::make('refund_number')->label(__('payments.fields.refund_number')), TextEntry::make('status')->label(__('payments.fields.status'))->formatStateUsing(fn (string $state): string => __('payments.status.'.$state)), TextEntry::make('amount')->label(__('payments.fields.amount')), TextEntry::make('reason')->label(__('payments.fields.reason'))])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('payment_number')->label(__('payments.fields.payment_number'))->searchable(), TextColumn::make('customer.name')->label(__('payments.fields.customer_id'))->placeholder(__('payments.walk_in'))->searchable(), TextColumn::make('amount')->label(__('payments.fields.amount')),
            TextColumn::make('paymentMethod.name')->label(__('payments.fields.payment_method_id')), TextColumn::make('payment_date')->label(__('payments.fields.payment_date'))->date()->sortable(), TextColumn::make('external_reference')->toggleable(isToggledHiddenByDefault: true)->label(__('payments.fields.external_reference'))->searchable(), TextColumn::make('status')->label(__('payments.fields.status'))->badge()->formatStateUsing(fn (string $state): string => __('payments.status.'.$state)),
        ])->filters([
            SelectFilter::make('status')->label(__('payments.fields.status'))->options(['draft' => __('Draft'), 'posted' => __('Posted')]),
            SelectFilter::make('customer_id')->label(__('payments.fields.customer_id'))->options(fn () => static::customers())->searchable(),
            Filter::make('dates')->label(__('payments.fields.dates'))->schema([DatePicker::make('from')->label(__('payments.fields.from')), DatePicker::make('until')->label(__('payments.fields.until'))->afterOrEqual('from')])->query(fn (Builder $q, array $data) => $q->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('payment_date', '>=', $d))->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('payment_date', '<=', $d))),
        ])->recordActions([MasterDetail::action(function (Schema $schema, Payment $record): Schema {
            $record->loadMissing(['allocations.invoice', 'refunds']);

            return self::infolist($schema);
        })->authorize('view'), ViewAction::make()])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCustomerPayments::route('/'), 'view' => Pages\ViewCustomerPayment::route('/{record}')];
    }
}
