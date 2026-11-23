<?php

namespace App\Modules\POS\Filament\Resources\PosDevices;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\POS\Filament\Resources\PosDevicePaymentMethods\PosDevicePaymentMethodResource;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Services\PosAccess;
use App\Modules\POS\Services\PosDeviceManagement;
use App\Modules\POS\Services\PosPermissions;
use App\Modules\POS\Services\PosReadModels;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PosDeviceResource extends Resource
{
    protected static ?string $model = PosDevice::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    protected static ?int $navigationSort = 70;

    public static function getNavigationGroup(): string
    {
        return __('pos.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('pos.devices');
    }

    public static function getModelLabel(): string
    {
        return __('pos.device');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pos.devices');
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
            ->whereIn('location_id', $branchIds)
            ->with(['location:id,name', 'registeredBy:id,name'])
            ->withCount('sessions');
    }

    public static function form(Schema $schema): Schema
    {
        $user = auth()->user();
        $branchIds = app(PosAccess::class)->branchIds($user, PosPermissions::DEVICES_MANAGE);
        $defaultLocationId = in_array((int) getPermissionsTeamId(), $branchIds, true)
            ? (int) getPermissionsTeamId()
            : ($branchIds[0] ?? null);

        return $schema->components([
            TextInput::make('name')->label(__('pos.fields.name'))->required()->maxLength(191),
            Select::make('location_id')
                ->label(__('pos.fields.branch'))
                ->options(fn (): array => Location::query()
                    ->whereIn('id', $branchIds)
                    ->where('type', 'branch')
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->default($defaultLocationId)
                ->required()
                ->disabledOn('edit')
                ->dehydrated(fn (string $operation): bool => $operation === 'create'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('device_code')->label(__('pos.fields.device_code')),
            TextEntry::make('name')->label(__('pos.fields.name')),
            TextEntry::make('location.name')->label(__('pos.fields.branch')),
            TextEntry::make('registeredBy.name')->label(__('pos.fields.registered_by')),
            TextEntry::make('registered_at')->label(__('pos.fields.registered_at'))->dateTime(),
            TextEntry::make('last_seen_at')->label(__('pos.fields.last_seen_at'))->dateTime(),
            TextEntry::make('revoked_at')->label(__('pos.fields.revoked_at'))->dateTime(),
            TextEntry::make('sessions_count')->label(__('pos.fields.shift_count')),
            TextEntry::make('payment_configurations')
                ->label(__('pos.device_payment_methods'))
                ->state(fn (PosDevice $record): string => collect(app(PosReadModels::class)
                    ->paymentMethodConfigurations(auth()->user(), (int) $record->id))
                    ->map(fn (array $configuration): string => $configuration['name']
                        .($configuration['account_name'] === null ? '' : ' - '.$configuration['account_name'])
                        .' ('.__($configuration['active'] ? 'pos.status.active' : 'pos.status.inactive').')')
                    ->implode(', '))
                ->placeholder('—'),
            TextEntry::make('is_active')->label(__('pos.fields.active'))->badge(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('device_code')->label(__('pos.fields.device_code'))->searchable()->sortable(),
            TextColumn::make('name')->label(__('pos.fields.name'))->searchable()->sortable(),
            TextColumn::make('location.name')->label(__('pos.fields.branch')),
            TextColumn::make('is_active')->label(__('pos.fields.active'))->badge()
                ->formatStateUsing(fn (bool $state): string => __($state ? 'pos.status.active' : 'pos.status.inactive')),
            TextColumn::make('revoked_at')->label(__('pos.fields.revoked_at'))->dateTime()->placeholder('—'),
            TextColumn::make('last_seen_at')->label(__('pos.fields.last_seen_at'))->dateTime()->placeholder('—'),
            TextColumn::make('sessions_count')->label(__('pos.fields.shift_count'))->numeric(),
        ])->filters([TernaryFilter::make('is_active')->label(__('pos.fields.active'))])
            ->recordAction(null)
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('paymentMethods')
                    ->label(__('pos.actions.payment_methods'))
                    ->url(fn (PosDevice $record): string => PosDevicePaymentMethodResource::getUrl('index', [
                        'tableFilters' => ['pos_device_id' => ['value' => $record->id]],
                    ])),
                Action::make('activate')
                    ->label(__('pos.actions.activate'))
                    ->visible(fn (PosDevice $record): bool => ! $record->is_active && $record->revoked_at === null)
                    ->action(fn (PosDevice $record) => app(PosDeviceManagement::class)
                        ->setActive(auth()->user(), $record, true)),
                Action::make('deactivate')
                    ->label(__('pos.actions.deactivate'))
                    ->visible(fn (PosDevice $record): bool => $record->is_active && $record->revoked_at === null)
                    ->requiresConfirmation()
                    ->action(fn (PosDevice $record) => app(PosDeviceManagement::class)
                        ->setActive(auth()->user(), $record, false)),
                Action::make('revoke')
                    ->label(__('pos.actions.revoke'))
                    ->visible(fn (PosDevice $record): bool => $record->revoked_at === null)
                    ->requiresConfirmation()
                    ->action(fn (PosDevice $record) => app(PosDeviceManagement::class)
                        ->revoke(auth()->user(), $record)),
            ])->toolbarActions([])->defaultSort('device_code');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosDevices::route('/'),
            'create' => Pages\CreatePosDevice::route('/create'),
            'view' => Pages\ViewPosDevice::route('/{record}'),
            'edit' => Pages\EditPosDevice::route('/{record}/edit'),
        ];
    }
}
