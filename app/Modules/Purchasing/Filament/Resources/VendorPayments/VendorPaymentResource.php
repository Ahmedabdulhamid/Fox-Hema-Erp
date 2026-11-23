<?php

namespace App\Modules\Purchasing\Filament\Resources\VendorPayments;

use App\Filament\ReadModelCache;
use App\Filament\RequestDisplayData;
use App\Models\MoneyFormatter;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Purchasing\Filament\Resources\VendorBills\VendorBillResource;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Purchasing\Services\SupplierFinancialBalances;
use App\Modules\Purchasing\Services\VendorPaymentAccess;
use App\Modules\Purchasing\Services\VendorPaymentTreasury;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryAccess;
use App\Modules\Treasury\Services\TreasuryAccounts;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use Brick\Math\BigDecimal;
use Closure;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VendorPaymentResource extends Resource
{
    protected static ?string $model = VendorPayment::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $recordTitleAttribute = 'payment_number';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('Purchasing');
    }

    public static function getModelLabel(): string
    {
        return __('Vendor payment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Supplier payments');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(VendorPaymentAccess::class)->scope(parent::getEloquentQuery(), auth()->user())
            ->with(['supplier', 'location', 'paymentMethod']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('supplier_id')->label(__('Supplier'))->options(fn (): array => self::supplierOptions())->searchable()->required()
                ->getSearchResultsUsing(fn (string $search): array => self::supplierOptions($search))
                ->getOptionLabelUsing(fn ($value): ?string => self::supplierOptions(value: $value)[$value] ?? null),
            Select::make('location_id')->label(__('vendor_payments.purchase_location'))->options(fn (): array => Location::where('is_active', true)
                ->when(! auth()->user()->isGlobalSuperAdmin(), fn ($query) => $query->whereKey(getPermissionsTeamId()))->pluck('name', 'id')->all())->required()->live()->helperText(__('vendor_payments.location_help'))
                ->afterStateUpdated(fn (Set $set) => $set('treasury_account_id', null)),
            DatePicker::make('payment_date')->label(__('vendor_payments.payment_date'))->default(now()->format('Y-m-d'))->maxDate(now())->required(),
            TextInput::make('amount')->label(__('Payment amount'))->suffix('EGP')->inputMode('decimal')->required()->helperText(__('vendor_payments.allocation_help')),
            Select::make('payment_method_id')->label(__('Payment method'))->options(fn (): array => app(ReadModelCache::class)->options('payments', PaymentMethod::where('is_active', true)->whereIn('type', ['cash', 'bank_transfer'])->orderBy('sort_order')->orderBy('id')))->live()->required()
                ->helperText(__('vendor_payments.method_help'))->afterStateUpdated(function (Set $set): void {
                    $set('treasury_account_id', null);
                    $set('external_reference', null);
                }),
            Select::make('treasury_account_id')->label(__('Treasury source account'))->options(fn (Get $get): array => self::sourceOptions($get('payment_method_id')))->searchable()->required()
                ->disabled(fn (Get $get): bool => blank($get('payment_method_id')))
                ->placeholder(fn (Get $get): string => blank($get('payment_method_id')) ? __('vendor_payments.choose_method_first') : __('vendor_payments.choose_source'))
                ->noOptionsMessage(__('vendor_payments.no_accounts'))
                ->helperText(__('vendor_payments.source_help')),
            TextInput::make('external_reference')->label(__('vendor_payments.transaction_reference'))->maxLength(255)
                ->required(function (Get $get): bool {
                    $methodId = $get('payment_method_id');
                    if (! is_scalar($methodId) || blank($methodId)) {
                        return false;
                    }

                    return (bool) (self::activePaymentMethod($methodId)?->requires_reference ?? false);
                }),
            Textarea::make('notes')->label(__('Notes'))->maxLength(16000),
        ]);
    }

    private static function activePaymentMethod(mixed $methodId): ?PaymentMethod
    {
        if (! is_scalar($methodId) || blank($methodId)) {
            return null;
        }

        return RequestDisplayData::remember('vendor-payment.active-method.'.$methodId, fn (): ?PaymentMethod => PaymentMethod::query()
            ->whereKey($methodId)->where('is_active', true)->first(['id', 'type', 'requires_reference']));
    }

    /** @return array<int|string, string> */
    private static function supplierOptions(string $search = '', mixed $value = null): array
    {
        app(VendorPaymentAccess::class)->authorize(auth()->user(), PurchasingPermissions::PAYMENTS_VIEW);
        $query = Supplier::where('is_active', true)->when($value !== null, fn ($query) => $query->whereKey($value))
            ->when(trim($search) !== '', fn ($query) => $query->where('name', 'like', '%'.mb_substr(trim($search), 0, 100).'%'))
            ->orderBy('name')->orderBy('id')->limit(50);

        return app(ReadModelCache::class)->options('suppliers', $query);
    }

    public static function sourceOptions(mixed $methodId): array
    {
        if (! is_scalar($methodId) || blank($methodId)) {
            return [];
        }

        try {
            $method = self::activePaymentMethod($methodId);
            if ($method === null || ! in_array($method->type, ['cash', 'bank_transfer'], true)) {
                return [];
            }
            $type = $method->type;
            $options = [];
            foreach (app(TreasuryAccounts::class)->visible(auth()->user()) as $account) {
                if (! $account->is_active || $account->currency !== 'EGP'
                    || ! (($type === 'cash' && in_array($account->type, ['branch_cashbox', 'central_cashbox'], true)) || ($type === 'bank_transfer' && $account->type === 'bank'))) {
                    continue;
                }
                try {
                    app(TreasuryAccess::class)->operation(auth()->user(), TreasuryPermissions::CREATE, $account->location_id);
                    $options[$account->id] = $account->name.' | EGP';
                    try {
                        app(TreasuryAccess::class)->operation(auth()->user(), TreasuryPermissions::BALANCES, $account->location_id);
                        $balance = RequestDisplayData::remember('treasury.account-selector-balance.'.$account->id,
                            fn (): string => app(TreasuryService::class)->balanceForDisplay(auth()->user(), $account));
                        $options[$account->id] .= ' | '.__('Available balance').': '.$balance;
                    } catch (AuthorizationException|HttpException) {
                        continue;
                    }
                } catch (AuthorizationException|HttpException) {
                    continue;
                }
            }

            return $options;
        } catch (AuthorizationException|HttpException) {
            return [];
        }
    }

    public static function infolist(Schema $schema, ?Closure $displayAmounts = null): Schema
    {
        $getAllocationRows = function (VendorPayment $record): array {
            app(VendorPaymentAccess::class)->authorize(auth()->user(), PurchasingPermissions::PAYMENTS_VIEW, $record->location_id);
            $query = $record->allocations()->whereHas('vendorBill', fn (Builder $query) => $query->where('supplier_id', $record->supplier_id)
                ->whereHas('purchaseOrder', fn (Builder $query) => $query->where('location_id', $record->location_id)))
                ->with(['vendorBill' => fn ($query) => $query->without('documentNumber')])->latest('id')->limit(50);

            return RequestDisplayData::remember('supplier.payment-allocation-rows.'.$record->getKey(),
                fn (): array => app(ReadModelCache::class)->remember('purchasing', ['payment-allocation-history', $record->id, $record->supplier_id, $record->location_id],
                    fn (): array => $query->get()->map(fn ($allocation): array => [
                        'vendorBill' => ['bill_number' => $allocation->vendorBill->bill_number, 'uuid' => $allocation->vendorBill->uuid],
                        'amount' => $allocation->amount, 'allocated_at' => $allocation->allocated_at?->toDateTimeString(),
                        'released_at' => $allocation->released_at?->toDateTimeString(),
                    ])->all(), config('read_cache.report_ttl'), $record->getConnection()));
        };

        return $schema->components([
            TextEntry::make('payment_number')->label(__('Payment number')),
            TextEntry::make('supplier.name')->label(__('Supplier')),
            TextEntry::make('payment_date')->label(__('vendor_payments.payment_date'))->date(),
            TextEntry::make('amount')->label(__('Payment amount')),
            TextEntry::make('status')->label(__('Status'))->badge(),
            TextEntry::make('allocation_summary')->label(__('Allocated payments'))->visible(fn (VendorPayment $record): bool => $record->idempotency_key !== null)
                ->state(function (VendorPayment $record) use ($displayAmounts): string {
                    try {
                        $amounts = $displayAmounts === null
                            ? app(SupplierFinancialBalances::class)->paymentForDisplay(auth()->user(), $record)
                            : $displayAmounts();
                    } catch (ValidationException $exception) {
                        return collect($exception->errors())->flatten()->implode(' ');
                    }

                    return MoneyFormatter::format($amounts['allocated']).' | '.__('Available advance').': '.MoneyFormatter::format($amounts['unallocated']);
                }),
            TextEntry::make('source')->label(__('Treasury source account'))->state(fn (VendorPayment $record): string => self::sourceSummary($record)),
            TextEntry::make('settlement')->label(__('Treasury settlement'))->state(fn (VendorPayment $record): string => $record->treasury_account_id === null ? __('Legacy / unlinked') : __('Treasury-backed')),
            TextEntry::make('movement')->label(__('Treasury movement'))->state(fn (VendorPayment $record): string => self::movementSummary($record)),
            TextEntry::make('recovery_reference')->label(__('Verified recovery reference'))->placeholder('—'),
            TextEntry::make('reversal_reason')->label(__('Recovery reason'))->placeholder('—'),
            TextEntry::make('posted_at')->label(__('Posted at'))->dateTime(),
            TextEntry::make('voided_at')->label(__('Recovered at'))->dateTime(),
            RepeatableEntry::make('bill_allocations')->label(__('Bill allocations'))->columnSpanFull()
                ->visible(fn (VendorPayment $record): bool => $record->location_id !== null && $record->idempotency_key !== null)
                ->state($getAllocationRows)
                ->schema([
                    TextEntry::make('vendorBill.bill_number')->label(__('Bill'))->url(fn (TextEntry $component): string => VendorBillResource::getUrl('view', ['record' => data_get($component->getContainer()->getConstantState(), 'vendorBill.uuid')])),
                    TextEntry::make('amount')->label(__('Allocation amount')),
                    TextEntry::make('allocated_at')->label(__('Allocated at'))->dateTime(),
                    TextEntry::make('released_at')->label(__('Released at'))->dateTime(),
                ]),
        ]);
    }

    public static function movementSummary(VendorPayment $record): string
    {
        try {
            $operation = app(VendorPaymentTreasury::class)->movement(auth()->user(), $record);
            if ($operation === null) {
                return $record->treasury_account_id === null ? __('Legacy / unlinked') : __('Not posted');
            }
            $account = RequestDisplayData::remember('supplier.payment-source-account.'.$operation->account_id,
                fn (): TreasuryAccount => TreasuryAccount::findOrFail($operation->account_id));
            $reversal = RequestDisplayData::remember('supplier.payment-treasury-reversal.'.$operation->id,
                fn () => $operation->newQuery()->where('reversal_of_id', $operation->id)->first());

            return $operation->uuid.' | '.$account->name.' | '.$operation->amount.' '.$operation->currency
                .($reversal === null ? '' : ' | '.__('Recovery movement').': '.$reversal->uuid);
        } catch (AuthorizationException|HttpException) {
            return __('Treasury viewing permission required');
        }
    }

    public static function sourceSummary(VendorPayment $record): string
    {
        if ($record->treasury_account_id === null) {
            return __('Legacy / unlinked');
        }
        try {
            $account = RequestDisplayData::remember('supplier.payment-source-account.'.$record->treasury_account_id,
                fn (): TreasuryAccount => TreasuryAccount::findOrFail($record->treasury_account_id));
            app(TreasuryAccess::class)->authorize(auth()->user(), TreasuryPermissions::VIEW, $account->location_id);
            $description = $account->name.' | '.$account->currency;
            try {
                app(TreasuryAccess::class)->operation(auth()->user(), TreasuryPermissions::BALANCES, $account->location_id);
                $balance = RequestDisplayData::remember('treasury.account-selector-balance.'.$account->id,
                    fn (): string => app(TreasuryService::class)->balanceForDisplay(auth()->user(), $account));

                return $description.' | '.__('Available balance').': '.$balance;
            } catch (AuthorizationException|HttpException) {
                return $description;
            }
        } catch (AuthorizationException|HttpException) {
            return __('Treasury viewing permission required');
        }
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->withSum([
            'allocations as allocated_amount' => fn (Builder $query) => $query->whereNull('released_at')
                ->whereHas('vendorBill', fn (Builder $query) => $query->where('status', 'posted')),
        ], 'amount'))->columns([
            TextColumn::make('payment_number')->label(__('Payment number'))->searchable(),
            TextColumn::make('supplier.name')->label(__('Supplier'))->searchable(),
            TextColumn::make('location.name')->label(__('Location'))->placeholder(__('Legacy / unlinked')),
            TextColumn::make('paymentMethod.name')->label(__('Payment method'))->placeholder(fn (VendorPayment $record): string => $record->method),
            TextColumn::make('external_reference')->label(__('vendor_payments.transaction_reference'))->searchable(),
            TextColumn::make('payment_date')->label(__('vendor_payments.payment_date'))->date()->sortable(),
            TextColumn::make('amount')->label(__('Payment amount')),
            TextColumn::make('available_advance')->label(__('Available advance'))
                ->state(fn (VendorPayment $record): string => $record->status === 'posted' && $record->voided_at === null
                    ? (string) BigDecimal::of($record->amount)->minus($record->allocated_amount ?? '0')->toScale(4) : '0.0000'),
            TextColumn::make('status')->label(__('Status'))->badge(),
            TextColumn::make('settlement')->label(__('Treasury settlement'))->state(fn (VendorPayment $record): string => $record->treasury_account_id === null ? __('Legacy / unlinked') : __('Treasury-backed')),
        ])->filters([
            SelectFilter::make('status')->label(__('Status'))->options(['draft' => __('Draft'), 'posted' => __('Posted'), 'void' => __('Reversed')]),
            SelectFilter::make('supplier_id')->label(__('Supplier'))->relationship('supplier', 'name')->searchable(),
            Filter::make('dates')->label(__('vendor_payments.payment_date'))->schema([DatePicker::make('from')->label(__('From')), DatePicker::make('until')->label(__('Until'))->afterOrEqual('from')])
                ->query(fn (Builder $query, array $data) => $query->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('payment_date', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('payment_date', '<=', $date))),
            Filter::make('unlinked')->label(__('Legacy / unlinked'))->query(fn (Builder $query): Builder => $query->whereNull('treasury_account_id')),
        ])->recordActions([ViewAction::make()])->toolbarActions([])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListVendorPayments::route('/'), 'advances' => Pages\ListSupplierAdvances::route('/advances'), 'create' => Pages\CreateVendorPayment::route('/create'), 'view' => Pages\ViewVendorPayment::route('/{record}')];
    }
}
