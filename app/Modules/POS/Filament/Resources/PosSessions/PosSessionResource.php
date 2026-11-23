<?php

namespace App\Modules\POS\Filament\Resources\PosSessions;

use App\Modules\AccessManagement\Models\User;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Services\PosAccess;
use App\Modules\POS\Services\PosCashMovementService;
use App\Modules\POS\Services\PosPermissions;
use App\Modules\POS\Services\PosShiftService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PosSessionResource extends Resource
{
    protected static ?string $model = PosSession::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $recordTitleAttribute = 'display_code';

    protected static bool $isGloballySearchable = false;

    protected static ?int $navigationSort = 72;

    public static function getNavigationGroup(): string
    {
        return __('pos.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('pos.shifts');
    }

    public static function getModelLabel(): string
    {
        return __('pos.shift');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pos.shifts');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PosAccess::class)->can($user, PosPermissions::VIEW);
    }

    public static function getEloquentQuery(): Builder
    {
        $locationId = (int) getPermissionsTeamId();
        app(PosAccess::class)->authorize(auth()->user(), PosPermissions::VIEW, $locationId);

        return parent::getEloquentQuery()
            ->where('location_id', $locationId)
            ->with([
                'openedBy:id,name',
                'closedBy:id,name',
                'closingApprovedBy:id,name',
                'posDevice:id,device_code,name',
                'location:id,name,code',
                'cashTreasuryAccount:id,name',
            ])
            ->withCount(['transactions as incomplete_transactions_count' => fn (Builder $query) => $query
                ->whereIn('sync_status', ['received', 'processing'])]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('display_code')->label(__('pos.fields.shift_number')),
            TextEntry::make('status')->label(__('pos.fields.status'))->badge(),
            TextEntry::make('openedBy.name')->label(__('pos.fields.cashier')),
            TextEntry::make('posDevice.device_code')->label(__('pos.fields.device_code')),
            TextEntry::make('location.name')->label(__('pos.fields.branch')),
            TextEntry::make('cashTreasuryAccount.name')->label(__('pos.fields.cash_drawer')),
            TextEntry::make('opened_at')->label(__('pos.fields.opened_at'))->dateTime(),
            TextEntry::make('closing_started_at')->label(__('pos.fields.closing_started_at'))->dateTime(),
            TextEntry::make('closed_at')->label(__('pos.fields.closed_at'))->dateTime(),
            TextEntry::make('opening_cash')->label(__('pos.fields.opening_cash')),
            TextEntry::make('expected_cash')->label(__('pos.fields.expected_cash')),
            TextEntry::make('counted_cash')->label(__('pos.fields.counted_cash')),
            TextEntry::make('cash_difference')->label(__('pos.fields.cash_difference')),
            TextEntry::make('cash_difference_reason')->label(__('pos.fields.difference_reason')),
            TextEntry::make('closingApprovedBy.name')->label(__('pos.fields.approved_by')),
            TextEntry::make('notes')->label(__('pos.fields.notes')),
        ]);
    }

    public static function closeAction(): Action
    {
        return Action::make('closeShift')
            ->label(__('pos.actions.close_shift'))
            ->button()
            ->color('danger')
            ->visible(fn (PosSession $record): bool => in_array($record->status, ['open', 'closing'], true)
                && app(PosAccess::class)->can(auth()->user(), PosPermissions::SHIFTS_CLOSE)
                && ((int) $record->opened_by === (int) auth()->id()
                    || app(PosAccess::class)->can(auth()->user(), PosPermissions::SHIFTS_APPROVE)))
            ->schema(fn (PosSession $record): array => [
                Hidden::make('closing_key')
                    ->default($record->closing_key ?? (string) Str::uuid7())
                    ->required(),
                TextInput::make('counted_cash')
                    ->label(__('pos.fields.counted_cash'))
                    ->helperText(fn (PosSession $record): string => __('pos.fields.closing_cash_help', [
                        'expected' => app(PosShiftService::class)->expectedClosingCash(auth()->user(), (int) $record->id),
                        'opening' => $record->opening_cash,
                    ]))
                    ->inputMode('decimal')
                    ->required(),
                ...array_map(
                    fn (array $tender): TextInput => TextInput::make('tender_counts.'.$tender['payment_method_id'])
                        ->label(__('pos.fields.tender_count', [
                            'name' => $tender['name'],
                            'expected' => $tender['expected_amount'],
                        ]))
                        ->inputMode('decimal')
                        ->required(),
                    app(PosShiftService::class)->expectedTenders(auth()->user(), (int) $record->id),
                ),
                Textarea::make('difference_reason')
                    ->label(__('pos.fields.difference_reason'))
                    ->maxLength(16000),
                Textarea::make('notes')->label(__('pos.fields.notes'))->maxLength(16000),
            ])
            ->action(function (Action $action, PosSession $record, array $data): void {
                $closingKey = (string) Arr::pull($data, 'closing_key');
                try {
                    $session = app(PosShiftService::class)->close(auth()->user(), (int) $record->id, $data, $closingKey);
                } catch (ValidationException $exception) {
                    Notification::make()->title(__('pos.notifications.shift_close_failed'))
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()->persistent()->send();
                    $livewire = $action->getLivewire();
                    $statePath = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
                    $errors = [];
                    foreach ($exception->errors() as $field => $messages) {
                        $errors[$statePath.'.'.$field] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
                $record->refresh();
                if ($session->status === 'closed') {
                    Notification::make()->title(__('pos.notifications.shift_closed'))->success()->send();

                    return;
                }
                Notification::make()->title(__('pos.notifications.closing_pending'))->warning()->send();
            });
    }

    public static function approveAction(): Action
    {
        return Action::make('approveClosing')
            ->label(__('pos.actions.approve_closing'))
            ->visible(fn (PosSession $record): bool => $record->status === 'closing'
                && $record->expected_cash !== null
                && app(PosAccess::class)->can(auth()->user(), PosPermissions::SHIFTS_APPROVE))
            ->schema([
                Textarea::make('reason')->label(__('pos.fields.approval_reason'))->required()->maxLength(16000),
            ])
            ->requiresConfirmation()
            ->action(function (PosSession $record, array $data): void {
                app(PosShiftService::class)->approveClosing(auth()->user(), (int) $record->id, $data['reason']);
                Notification::make()->title(__('pos.notifications.shift_closed'))->success()->send();
            });
    }

    public static function cashMovementAction(): Action
    {
        return Action::make('cashMovement')
            ->label(__('pos.actions.cash_movement'))
            ->visible(fn (PosSession $record): bool => $record->status === 'open'
                && app(PosAccess::class)->can(auth()->user(), PosPermissions::CASH_MOVEMENTS))
            ->schema([
                Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid7())->required(),
                Select::make('movement_type')->label(__('pos.fields.movement_type'))
                    ->options([
                        'cash_in' => __('pos.movement_types.cash_in'),
                        'cash_out' => __('pos.movement_types.cash_out'),
                        'cash_drop' => __('pos.movement_types.cash_drop'),
                    ])->required(),
                TextInput::make('amount')->label(__('pos.fields.amount'))->inputMode('decimal')->required(),
                Textarea::make('reason')->label(__('pos.fields.reason'))->required()->maxLength(191),
            ])
            ->action(function (PosSession $record, array $data): void {
                app(PosCashMovementService::class)->post(
                    auth()->user(),
                    (int) $record->id,
                    $data['movement_type'],
                    $data['amount'],
                    $data['reason'],
                    $data['idempotency_key'],
                );
                Notification::make()->title(__('pos.notifications.cash_movement_posted'))->success()->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('display_code')->label(__('pos.fields.shift_number'))->searchable()->sortable(),
            TextColumn::make('status')->label(__('pos.fields.status'))->badge()->sortable(),
            TextColumn::make('openedBy.name')->label(__('pos.fields.cashier'))->searchable()->sortable(),
            TextColumn::make('location.name')->label(__('pos.fields.branch'))->searchable(),
            TextColumn::make('posDevice.device_code')->label(__('pos.fields.device_code'))->searchable()->sortable(),
            TextColumn::make('cashTreasuryAccount.name')->label(__('pos.fields.cash_drawer')),
            TextColumn::make('opening_cash')->label(__('pos.fields.opening_cash')),
            TextColumn::make('expected_cash')->label(__('pos.fields.expected_cash'))->placeholder('—'),
            TextColumn::make('counted_cash')->label(__('pos.fields.counted_cash'))->placeholder('—'),
            TextColumn::make('cash_difference')->label(__('pos.fields.cash_difference'))->placeholder('—'),
            TextColumn::make('late_reconciliation')->label(__('pos.fields.late_reconciliation'))
                ->state(function (PosSession $record): string {
                    if ($record->status !== 'closed') {
                        return '—';
                    }
                    $summary = app(PosShiftService::class)->lateReconciliation(auth()->user(), (int) $record->id);

                    return $summary['late_sales'] === 0 ? '—' : __('pos.late_summary', [
                        'count' => $summary['late_sales'], 'amount' => $summary['late_amount'], 'difference' => $summary['cash_difference'],
                    ]).($summary['requires_review'] ? ' · '.__('pos.late_review_required') : '');
                })->wrap(),
            TextColumn::make('incomplete_transactions_count')->label(__('pos.fields.incomplete_transactions'))->numeric(),
            TextColumn::make('opened_at')->label(__('pos.fields.opened_at'))->dateTime()->sortable(),
            TextColumn::make('closed_at')->label(__('pos.fields.closed_at'))->dateTime()->placeholder('—')->sortable(),
        ])->filters([
            SelectFilter::make('status')->label(__('pos.fields.status'))
                ->options(['open' => __('pos.status.open'), 'closing' => __('pos.status.closing'), 'closed' => __('pos.status.closed')]),
        ])->recordAction(null)
            ->recordActions([
                ViewAction::make(),
                static::cashMovementAction(),
                static::closeAction(),
                static::approveAction(),
            ])->toolbarActions([])->defaultSort('opened_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosSessions::route('/'),
            'view' => Pages\ViewPosSession::route('/{record}'),
        ];
    }
}
