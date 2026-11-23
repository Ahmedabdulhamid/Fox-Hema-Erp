<?php

namespace App\Modules\POS\Filament\Resources\PosDevicePaymentMethods;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosDevicePaymentMethod;
use App\Modules\POS\Services\PosAccess;
use App\Modules\POS\Services\PosPermissions;
use App\Modules\POS\Services\PosReadModels;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PosDevicePaymentMethodResource extends Resource
{
    protected static ?string $model = PosDevicePaymentMethod::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $recordTitleAttribute = 'display_name';

    protected static bool $isGloballySearchable = false;

    protected static ?int $navigationSort = 71;

    public static function getNavigationGroup(): string
    {
        return __('pos.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('pos.device_payment_methods');
    }

    public static function getModelLabel(): string
    {
        return __('pos.device_payment_method');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pos.device_payment_methods');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(PosAccess::class)->can($user, PosPermissions::DEVICES_MANAGE);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function getEloquentQuery(): Builder
    {
        $branchIds = app(PosAccess::class)->branchIds(auth()->user(), PosPermissions::DEVICES_MANAGE);

        return parent::getEloquentQuery()
            ->whereHas('posDevice', fn (Builder $query) => $query->whereIn('location_id', $branchIds))
            ->with([
                'posDevice:id,device_code,name,location_id',
                'paymentMethod:id,code,name,type',
                'treasuryAccount:id,name,type,location_id',
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        $branchIds = app(PosAccess::class)->branchIds(auth()->user(), PosPermissions::DEVICES_MANAGE);

        return $schema->components([
            Group::make()->columnSpanFull()->columns(1)->schema([
                Select::make('pos_device_id')
                    ->label(__('pos.fields.device'))
                    ->options(fn (): array => PosDevice::query()
                        ->whereIn('location_id', $branchIds)
                        ->whereNull('revoked_at')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('payment_methods', [[
                            'payment_method_id' => null,
                            'treasury_account_id' => null,
                            'is_active' => true,
                        ]]);
                    })
                    ->helperText(fn (string $operation): string => __(
                        $operation === 'edit' ? 'pos.hints.device_read_only' : 'pos.hints.device',
                    ))
                    ->disabledOn('edit')
                    ->dehydrated()
                    ->columnSpanFull(),
                Repeater::make('payment_methods')
                    ->label(__('pos.fields.payment_methods'))
                    ->schema([
                        Select::make('payment_method_id')
                            ->label(__('pos.fields.payment_method'))
                            ->options(function (Get $get): array {
                                if (blank($get('../../pos_device_id'))) {
                                    return [];
                                }
                                $options = app(PosReadModels::class)->paymentMethodOptions(
                                    auth()->user(),
                                    (int) $get('../../pos_device_id'),
                                );
                                $currentId = $get('payment_method_id');
                                if (filled($currentId) && ! array_key_exists((int) $currentId, $options)) {
                                    $currentMethod = PaymentMethod::query()->whereKey($currentId)->first(['id', 'name']);
                                    if ($currentMethod !== null) {
                                        $options[(int) $currentMethod->id] = $currentMethod->name.' ('.__('pos.status.inactive').')';
                                    }
                                }

                                return $options;
                            })
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('treasury_account_id', null))
                            ->placeholder(__('pos.hints.select_device_first')),
                        Select::make('treasury_account_id')
                            ->label(__('pos.fields.treasury_account'))
                            ->visible(fn (Get $get): bool => filled($get('payment_method_id'))
                                && PaymentMethod::query()->whereKey($get('payment_method_id'))->value('type') === 'cash')
                            ->options(fn (Get $get): array => filled($get('../../pos_device_id')) && filled($get('payment_method_id'))
                                ? app(PosReadModels::class)->treasuryAccountOptions(
                                    auth()->user(),
                                    (int) $get('../../pos_device_id'),
                                    (int) $get('payment_method_id'),
                                )
                                : [])
                            ->searchable()
                            ->required(fn (Get $get): bool => filled($get('payment_method_id'))
                                && PaymentMethod::query()->whereKey($get('payment_method_id'))->value('type') === 'cash')
                            ->disabled(fn (Get $get): bool => blank($get('../../pos_device_id')) || blank($get('payment_method_id')))
                            ->placeholder(__('pos.hints.select_payment_method_first'))
                            ->helperText(__('pos.hints.treasury_account')),
                        Toggle::make('is_active')->label(__('pos.fields.active'))->default(true)->required(),
                    ])
                    ->minItems(fn (string $operation): int => $operation === 'create' ? 1 : 0)
                    ->defaultItems(1)
                    ->addActionLabel(__('pos.actions.add_payment_method'))
                    ->reorderable(false)
                    ->compact()
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('posDevice.name')->label(__('pos.fields.device')),
            TextEntry::make('paymentMethod.name')->label(__('pos.fields.payment_method')),
            TextEntry::make('paymentMethod.type')->label(__('pos.fields.payment_type')),
            TextEntry::make('treasuryAccount.name')->label(__('pos.fields.treasury_account')),
            TextEntry::make('is_active')->label(__('pos.fields.active'))->badge(),
            TextEntry::make('updated_at')->label(__('pos.fields.updated_at'))->dateTime(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $branchIds = app(PosAccess::class)->branchIds(auth()->user(), PosPermissions::DEVICES_MANAGE);

        return $table->columns([
            TextColumn::make('posDevice.name')->label(__('pos.fields.device'))->searchable()->sortable(),
            TextColumn::make('paymentMethod.name')->label(__('pos.fields.payment_method'))->searchable()->sortable(),
            TextColumn::make('paymentMethod.type')->label(__('pos.fields.payment_type')),
            TextColumn::make('treasuryAccount.name')->label(__('pos.fields.treasury_account')),
            TextColumn::make('is_active')->label(__('pos.fields.active'))->badge()
                ->formatStateUsing(fn (bool $state): string => __($state ? 'pos.status.active' : 'pos.status.inactive')),
        ])->filters([
            SelectFilter::make('pos_device_id')
                ->label(__('pos.fields.device'))
                ->options(fn (): array => PosDevice::query()->whereIn('location_id', $branchIds)->orderBy('name')->pluck('name', 'id')->all()),
            TernaryFilter::make('is_active')->label(__('pos.fields.active')),
        ])->recordAction(null)
            ->recordActions([EditAction::make()])
            ->toolbarActions([])
            ->defaultSort('pos_device_id');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosDevicePaymentMethods::route('/'),
            'create' => Pages\CreatePosDevicePaymentMethod::route('/create'),
            'edit' => Pages\EditPosDevicePaymentMethod::route('/{record}/edit'),
        ];
    }
}
