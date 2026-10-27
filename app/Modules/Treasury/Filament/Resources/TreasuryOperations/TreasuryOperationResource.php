<?php

namespace App\Modules\Treasury\Filament\Resources\TreasuryOperations;

use App\Modules\Products\Filament\MasterDetail;
use App\Modules\Purchasing\Filament\Resources\VendorPayments\VendorPaymentResource;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Treasury\Models\TreasuryOperation;
use App\Modules\Treasury\Services\TreasuryWorkspace;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class TreasuryOperationResource extends Resource
{
    protected static ?string $model = TreasuryOperation::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $recordTitleAttribute = 'code';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('Treasury');
    }

    public static function getModelLabel(): string
    {
        return __('Treasury voucher');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Treasury payment history');
    }

    public static function getNavigationItems(): array
    {
        if (! static::canViewAny()) {
            return [];
        }
        $items = [];
        foreach (['receipts' => 'Cash receipts', 'payments' => 'Cash payments', 'transfers' => 'Internal transfers'] as $page => $label) {
            $items[] = NavigationItem::make(__($label))->group(__('Treasury'))->url(static::getUrl($page))
                ->isActiveWhen(fn (): bool => request()->routeIs(static::getRouteBaseName().'.'.$page));
        }

        return $items;
    }

    public static function getEloquentQuery(): Builder
    {
        return app(TreasuryWorkspace::class)->operations(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        $kind = $schema->getLivewire()->kind ?? 'receipt';

        return $schema->components([
            Select::make('account_id')->label($kind === 'transfer' ? __('Source account') : __('Treasury account'))->required()->searchable()
                ->options(fn (): array => app(TreasuryWorkspace::class)->accountOptions(auth()->user())),
            Select::make('destination_account_id')->label(__('Destination account'))->required()->searchable()->visible($kind === 'transfer')
                ->dehydrated($kind === 'transfer')->different('account_id')->options(fn (): array => app(TreasuryWorkspace::class)->accountOptions(auth()->user())),
            TextInput::make('amount')->label(__('Amount'))->inputMode('decimal')->suffix('EGP')->required(),
            DatePicker::make('effective_date')->label(__('Transaction date'))->default(now()->toDateString())->maxDate(now())->required(),
            Select::make('source_type')->label(__('Movement purpose'))->options(['external_capital' => __('External capital'), 'external_expense' => __('External expense'), 'external_other' => __('Other external movement')])
                ->required()->visible(in_array($kind, ['receipt', 'payment'], true))->dehydrated(in_array($kind, ['receipt', 'payment'], true)),
            TextInput::make('source_reference')->label(__('Transaction reference'))->required()->maxLength(191)
                ->visible(in_array($kind, ['receipt', 'payment'], true))->dehydrated(in_array($kind, ['receipt', 'payment'], true))
                ->helperText(__('Use the original external transaction reference. Supplier settlements belong in Supplier payments.')),
            Textarea::make('reason')->label(__('Reason'))->required()->maxLength(16000),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('code')->label(__('Code'))->placeholder(__('Legacy / unlinked')),
            TextEntry::make('uuid')->label(__('Reference')),
            TextEntry::make('type')->label(__('Entry type'))->formatStateUsing(fn (string $state): string => __(ucwords(str_replace('_', ' ', $state)))),
            TextEntry::make('lifecycle')->label(__('Status'))->state(fn (TreasuryOperation $record): string => self::statusLabel($record))->badge(),
            TextEntry::make('account.name')->label(__('Treasury account')),
            TextEntry::make('destinationAccount.name')->label(__('Destination account')),
            TextEntry::make('account.location.name')->label(__('Location'))->placeholder(__('Central administration')),
            TextEntry::make('amount')->label(__('Amount')),
            TextEntry::make('effective_date')->label(__('Transaction date'))->date(),
            TextEntry::make('source_type')->label(__('Source')),
            TextEntry::make('source_reference')->label(__('Transaction reference')),
            TextEntry::make('reason')->label(__('Reason')),
            TextEntry::make('posted_at')->label(__('Posted at'))->dateTime(),
            TextEntry::make('cancelled_at')->label(__('Cancelled at'))->dateTime(),
            TextEntry::make('cancellation_reason')->label(__('Cancellation reason')),
            TextEntry::make('reversal.uuid')->label(__('Reversal reference')),
            TextEntry::make('supplier_payment')->label(__('Supplier payment'))->state(function (TreasuryOperation $record): ?string {
                if ($record->vendor_payment_id === null) {
                    return null;
                }
                $payment = VendorPayment::find($record->vendor_payment_id);

                return $payment !== null && Gate::allows('view', $payment) ? $payment->payment_number : null;
            })->url(function (TreasuryOperation $record): ?string {
                if ($record->vendor_payment_id === null) {
                    return null;
                }
                $payment = VendorPayment::find($record->vendor_payment_id);

                return $payment !== null && Gate::allows('view', $payment) ? VendorPaymentResource::getUrl('view', ['record' => $payment]) : null;
            }),
        ]);
    }

    public static function statusLabel(TreasuryOperation $operation): string
    {
        if ($operation->cancelled_at !== null) {
            return __('Cancelled');
        }
        if ($operation->reversal !== null) {
            return __('Reversed');
        }

        return __(ucfirst($operation->status));
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->label(__('Code'))->searchable()->sortable()->placeholder(__('Legacy / unlinked')),
            TextColumn::make('uuid')->toggleable(isToggledHiddenByDefault: true)->label(__('Reference'))->searchable()->limit(12)->copyable(),
            TextColumn::make('account.name')->label(__('Treasury account')),
            TextColumn::make('destinationAccount.name')->toggleable(isToggledHiddenByDefault: true)->label(__('Destination account')),
            TextColumn::make('account.location.name')->toggleable(isToggledHiddenByDefault: true)->label(__('Location'))->placeholder(__('Central administration')),
            TextColumn::make('type')->label(__('Entry type'))->formatStateUsing(fn (string $state): string => __(ucwords(str_replace('_', ' ', $state)))),
            TextColumn::make('amount')->label(__('Amount'))->sortable(),
            TextColumn::make('method')->toggleable(isToggledHiddenByDefault: true)->label(__('Payment method'))->state(fn (TreasuryOperation $record): string => __($record->type === 'transfer' ? 'Internal transfer' : ($record->account->type === 'bank' ? 'Bank transfer' : 'Cash'))),
            TextColumn::make('effective_date')->label(__('Transaction date'))->date()->sortable(),
            TextColumn::make('lifecycle')->label(__('Status'))->state(fn (TreasuryOperation $record): string => self::statusLabel($record))->badge(),
            TextColumn::make('source_type')->toggleable(isToggledHiddenByDefault: true)->label(__('Source')),
            TextColumn::make('source_reference')->toggleable(isToggledHiddenByDefault: true)->label(__('Transaction reference'))->searchable(),
        ])->filters([
            SelectFilter::make('account')->label(__('Treasury account'))->options(fn (): array => app(TreasuryWorkspace::class)->accountOptions(auth()->user()))
                ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $id) => $query->where(fn (Builder $query) => $query->where('account_id', $id)->orWhere('destination_account_id', $id)))),
            SelectFilter::make('lifecycle')->label(__('Status'))->options(['draft' => __('Draft'), 'posted' => __('Posted'), 'cancelled' => __('Cancelled'), 'reversed' => __('Reversed')])
                ->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? null) {
                        'draft' => $query->where('status', 'draft')->whereNull('cancelled_at'),
                        'cancelled' => $query->whereNotNull('cancelled_at'),
                        'posted' => $query->where('status', 'posted')->whereDoesntHave('reversal'),
                        'reversed' => $query->whereHas('reversal'),
                        default => $query,
                    };
                }),
            SelectFilter::make('source_type')->label(__('Source'))->options(['vendor_payment' => __('Supplier payment'), 'payment' => __('Payment'), 'internal_transfer' => __('Internal transfer'), 'external_capital' => __('External capital'), 'external_expense' => __('External expense'), 'external_other' => __('Other external movement'), 'treasury_reversal' => __('Reversal'), 'opening_balance' => __('Opening balance')]),
            Filter::make('dates')->label(__('Transaction date'))->schema([DatePicker::make('from')->label(__('From')), DatePicker::make('until')->label(__('Until'))->afterOrEqual('from')])
                ->query(fn (Builder $query, array $data) => $query->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('effective_date', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('effective_date', '<=', $date))),
        ])->recordActions([MasterDetail::action(fn (Schema $schema): Schema => self::infolist($schema))->authorize('view'), ViewAction::make()])->toolbarActions([])->defaultSort('id', 'desc')->paginationPageOptions([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTreasuryOperations::route('/'), 'receipts' => Pages\ListCashReceipts::route('/receipts'),
            'payments' => Pages\ListCashPayments::route('/payments'), 'transfers' => Pages\ListInternalTransfers::route('/transfers'),
            'create' => Pages\CreateTreasuryOperation::route('/create'), 'view' => Pages\ViewTreasuryOperation::route('/{record}')];
    }
}
