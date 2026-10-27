<?php

namespace App\Modules\Treasury\Filament\Resources\TreasuryAccounts;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryWorkspace;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TreasuryAccountResource extends Resource
{
    protected static ?string $model = TreasuryAccount::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('Treasury');
    }

    public static function getModelLabel(): string
    {
        return __('Treasury account');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Treasury accounts');
    }

    public static function types(): array
    {
        return array_map(fn (string $label): string => __($label), [
            'branch_cashbox' => 'Branch cashbox', 'central_cashbox' => 'Central cashbox', 'location_cashbox' => 'Location cashbox', 'bank' => 'Bank account',
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return app(TreasuryWorkspace::class)->accountsWithBalances(auth()->user())->with('location');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(191),
            Select::make('type')->label(__('Account type'))->options(self::types())->required()->live()->disabledOn('edit')->dehydrated(fn (string $operation): bool => $operation === 'create')
                ->afterStateUpdated(function (Set $set): void {
                    $set('bank_name', null);
                    $set('account_identifier', null);
                }),
            Select::make('ownership')->label(__('Ownership'))->options(['location' => __('Location'), 'central' => __('Central administration')])->required()->live()
                ->disabledOn('edit')->dehydrated(fn (string $operation): bool => $operation === 'create')
                ->afterStateUpdated(fn (Set $set) => $set('location_id', null)),
            Select::make('location_id')->label(__('Location'))->options(fn (): array => Location::where('is_active', true)
                ->when(! auth()->user()->isGlobalSuperAdmin() && ! app(TreasuryWorkspace::class)->allows(auth()->user(), TreasuryPermissions::OPERATE_ALL_LOCATIONS, null),
                    fn (Builder $query) => $query->whereKey(getPermissionsTeamId()))->orderBy('name')->pluck('name', 'id')->all())
                ->required(fn (Get $get): bool => $get('ownership') === 'location')->disabledOn('edit')->dehydrated(fn (string $operation): bool => $operation === 'create'),
            TextInput::make('currency')->label(__('Currency'))->default('EGP')->readOnly()->required()->disabledOn('edit')->dehydrated(fn (string $operation): bool => $operation === 'create'),
            TextInput::make('bank_name')->label(__('Bank name'))->maxLength(120)->visible(fn (Get $get): bool => $get('type') === 'bank'),
            TextInput::make('account_identifier')->label(__('Account identifier'))->maxLength(120)->visible(fn (Get $get): bool => $get('type') === 'bank'),
            Toggle::make('is_active')->label(__('Active'))->default(true)->required(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('code')->label(__('Code')),
            TextEntry::make('name')->label(__('Name')),
            TextEntry::make('type')->label(__('Account type'))->formatStateUsing(fn (string $state): string => self::types()[$state] ?? $state),
            TextEntry::make('ownership')->label(__('Ownership')),
            TextEntry::make('location.name')->label(__('Location'))->placeholder(__('Central administration')),
            TextEntry::make('currency')->label(__('Currency')),
            TextEntry::make('ledger_balance')->label(__('Available balance'))->placeholder(__('Balance viewing permission required')),
            TextEntry::make('is_active')->label(__('Active'))->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No')),
            TextEntry::make('bank_name')->label(__('Bank name')),
            TextEntry::make('account_identifier')->label(__('Account identifier')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->label(__('Code'))->searchable()->sortable(),
            TextColumn::make('name')->label(__('Name'))->searchable(),
            TextColumn::make('type')->label(__('Account type'))->formatStateUsing(fn (string $state): string => self::types()[$state] ?? $state),
            TextColumn::make('location.name')->label(__('Location'))->placeholder(__('Central administration')),
            TextColumn::make('currency')->label(__('Currency')),
            TextColumn::make('ledger_balance')->label(__('Available balance'))->placeholder(__('Restricted')),
            TextColumn::make('is_active')->label(__('Active'))->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No')),
        ])->filters([SelectFilter::make('type')->label(__('Account type'))->options(self::types()), TernaryFilter::make('is_active')->label(__('Active'))])
            ->recordAction(null)->recordActions([ViewAction::make(), EditAction::make()])->toolbarActions([])->defaultSort('code')->paginationPageOptions([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTreasuryAccounts::route('/'), 'create' => Pages\CreateTreasuryAccount::route('/create'),
            'view' => Pages\ViewTreasuryAccount::route('/{record}'), 'edit' => Pages\EditTreasuryAccount::route('/{record}/edit')];
    }
}
