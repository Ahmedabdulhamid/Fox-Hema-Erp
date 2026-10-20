<?php

namespace App\Modules\AccessManagement\Filament\Resources\Locations;

use App\Modules\AccessManagement\Filament\Resources\Locations\Pages\CreateLocation;
use App\Modules\AccessManagement\Filament\Resources\Locations\Pages\EditLocation;
use App\Modules\AccessManagement\Filament\Resources\Locations\Pages\ListLocations;
use App\Modules\AccessManagement\Filament\Resources\Locations\RelationManagers\UsersRelationManager;
use App\Modules\AccessManagement\Models\Location;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static ?string $recordRouteKeyName = 'slug';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): string
    {
        return __('Users & Permissions');
    }

    public static function getModelLabel(): string
    {
        return __('Location');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Locations');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Location details'))->description(__('Add a branch or warehouse to your operating network.'))->icon('heroicon-m-building-storefront')->schema([
                TextInput::make('name')->label(__('Location Name'))->placeholder(__('e.g. Downtown Branch'))->required()->maxLength(255)->columnSpanFull(),
                // TextInput::make('code')->label(__('Location Code'))->placeholder(__('e.g. BR-01'))->required()->maxLength(40)->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule)->dehydrateStateUsing(fn ($state) => strtoupper(trim($state)))->readOnly(),
                Select::make('type')->label(__('Location Type'))->options(['branch' => __('Branch'), 'warehouse' => __('Warehouse')])->native(false)->required(),
                Toggle::make('is_active')->label(__('Active location'))->helperText(__('Location is active and can be used.'))->default(true)->required(),
            ])->columns(2)->columnSpanFull(),
            Section::make(__('Contact details'))->description(__('Optional address and phone information.'))->icon('heroicon-m-map-pin')->schema([
                Textarea::make('address')->label(__('Address'))->placeholder(__('Enter full address...'))->maxLength(2000)->rows(4),
                TextInput::make('phone')->label(__('Phone'))->placeholder(__('+20 10 1234 5678'))->tel()->maxLength(40),
            ])->columns(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('Name'))->searchable()->sortable(), TextColumn::make('code')->label(__('Code'))->searchable()->sortable(),
            TextColumn::make('type')->label(__('Type'))->formatStateUsing(fn (string $state): string => __(ucfirst($state)))->badge(), IconColumn::make('is_active')->label(__('Active'))->boolean(),
            TextColumn::make('users_count')->counts('users')->label(__('Users')),
        ])->filters([
            SelectFilter::make('type')->label(__('Type'))->options(['branch' => __('Branch'), 'warehouse' => __('Warehouse')]),
            TernaryFilter::make('is_active')->label(__('Active')),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        return $user?->isGlobalSuperAdmin() ? $query : $query->whereIn('id', $user->locations()->select('locations.id'));
    }

    public static function getPages(): array
    {
        return ['index' => ListLocations::route('/'), 'create' => CreateLocation::route('/create'), 'edit' => EditLocation::route('/{record}/edit')];
    }

    public static function getRelations(): array
    {
        return [UsersRelationManager::class];
    }
}
