<?php

namespace App\Modules\AccessManagement\Filament\Resources\Users;

use App\Modules\AccessManagement\Filament\Resources\Users\Pages\CreateUser;
use App\Modules\AccessManagement\Filament\Resources\Users\Pages\EditUser;
use App\Modules\AccessManagement\Filament\Resources\Users\Pages\ListUsers;
use App\Modules\AccessManagement\Filament\Resources\Users\Pages\ViewUser;
use App\Modules\AccessManagement\Filament\Resources\Users\RelationManagers\LocationsRelationManager;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\PermissionGroups;
use App\Modules\AccessManagement\Services\RoleAccessManager;
use App\Modules\AccessManagement\Services\UserAccessManager;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Unique;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return __('Users & Permissions');
    }

    public static function getModelLabel(): string
    {
        return __('User');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Users');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Personal information'))->description(__('Basic identity and contact details.'))->icon('heroicon-m-user')->schema([
                TextInput::make('name')->label(__('Full Name'))->placeholder(__('e.g. Ahmed Mohamed'))->required()->maxLength(255),
                TextInput::make('email')->label(__('Email Address'))->placeholder(__('e.g. ahmed@example.com'))->email()->required()->maxLength(255)->unique(ignoreRecord: true),
            ])->columns(2)->columnSpanFull(),
            Section::make(__('Account security'))->description(__('Configure login status and password requirements.'))->icon('heroicon-m-lock-closed')->schema([
                TextInput::make('password')->label(__('Password'))->password()->revealable()->required(fn (string $operation) => $operation === 'create')->dehydrated(fn ($state) => filled($state))->rule(Password::min(12))->same('password_confirmation')->visible(fn (string $operation) => $operation !== 'view'),
                TextInput::make('password_confirmation')->label(__('Confirm Password'))->password()->revealable()->requiredWith('password')->dehydrated(false)->visible(fn (string $operation) => $operation !== 'view'),
                Toggle::make('is_active')->label(__('Active account'))->helperText(__('User can sign in to the system.'))->default(true),
                Toggle::make('must_change_password')->label(__('Force password change'))->helperText(__('User must choose a new password on next login.'))->default(true),
            ])->columns(2)->columnSpanFull(),
            Section::make(__('Location access'))->description(__('Choose the branches and warehouses this user can access.'))->icon('heroicon-m-map-pin')->schema([
                Select::make('location_ids')->label(__('Assigned Locations'))->multiple()->searchable()->preload()->live()
                    ->options(function (): array {
                        $query = Location::query()->where('is_active', true)->orderBy('name');
                        if (! auth()->user()->isGlobalSuperAdmin()) {
                            $query->whereIn('id', auth()->user()->locations()->pluck('locations.id'));
                        }

                        return $query->pluck('name', 'id')->all();
                    })
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        $ids = $get('location_ids') ?? [];
                        if (! in_array((int) $get('access_location_id'), array_map('intval', $ids), true)) {
                            $set('access_location_id', $ids[0] ?? (getPermissionsTeamId() ?? 'global'));
                            $set('role_ids', []);
                            $set('permission_ids', []);
                        }
                    })->visible(fn (string $operation): bool => $operation !== 'edit'),
                Select::make('access_location_id')->label(__('Permissions Location'))->required()->live()
                    ->default(fn () => getPermissionsTeamId() ?? 'global')
                    ->helperText(__('Roles and additional permissions are saved only for the selected location. Assigning a location alone does not grant panel access.'))
                    ->options(function (Get $get, ?User $record): array {
                        $ids = $record ? $record->locations()->pluck('locations.id')->all() : ($get('location_ids') ?? []);
                        $query = Location::query()->where('is_active', true)->whereIn('id', $ids)->orderBy('name');
                        if (! auth()->user()->isGlobalSuperAdmin()) {
                            $query->whereKey(getPermissionsTeamId());
                        }
                        $options = $query->pluck('name', 'id')->all();

                        return auth()->user()->isGlobalSuperAdmin() ? $options + ['global' => __('Global administration scope')] : $options;
                    })
                    ->afterStateUpdated(function ($state, Set $set, ?User $record): void {
                        if ($record !== null) {
                            app(UserAccessManager::class)->validateScope($state === 'global' ? null : (int) $state, $record->locations()->pluck('locations.id')->all());
                        }
                        $assignments = $record ? app(UserAccessManager::class)->assignments($record, $state === 'global' ? null : (int) $state) : ['role_ids' => [], 'permission_ids' => []];
                        foreach ($assignments as $field => $ids) {
                            $set($field, $ids);
                        }
                    }),
                Text::make(__('Global assignments do not grant access inside a location. To reuse them, load them below, review the selections, then save.'))
                    ->visible(fn (?User $record): bool => auth()->user()->isGlobalSuperAdmin() && $record !== null),
                Actions::make([
                    Action::make('loadGlobalAssignments')->label(__('Load global assignments into selected location'))
                        ->visible(fn (Get $get, ?User $record): bool => auth()->user()->isGlobalSuperAdmin() && $record !== null && filled($get('access_location_id')) && $get('access_location_id') !== 'global')
                        ->action(function (Get $get, Set $set, User $record): void {
                            abort_unless(auth()->user()->isGlobalSuperAdmin(), 403);
                            app(UserAccessManager::class)->validateScope((int) $get('access_location_id'), $record->locations()->pluck('locations.id')->all());
                            $assignments = app(UserAccessManager::class)->assignments($record, null);
                            $assignments['role_ids'] = Role::query()->whereIn('id', $assignments['role_ids'])->where('name', '!=', 'super_admin')->pluck('id')->all();
                            foreach ($assignments as $field => $ids) {
                                $set($field, array_values(array_unique([...($get($field) ?? []), ...$ids])));
                            }
                        }),
                ])->key('accessAssignments')->visible(fn (string $operation): bool => $operation === 'edit'),
                Select::make('role_ids')->label(__('Roles in Selected Location'))->multiple()->searchable()->preload()->live()
                    ->helperText(__('A role without permissions does not grant panel access. Global role templates must also be assigned in the selected location.'))
                    ->options(fn (Get $get, ?User $record) => Role::query()->where('guard_name', 'web')
                        ->when(! ($record?->isGlobalSuperAdmin() && $get('access_location_id') === 'global'), fn (Builder $query) => $query->where('name', '!=', 'super_admin'))
                        ->where(fn (Builder $query) => $query->whereNull('location_id')->orWhere('location_id', $get('access_location_id') === 'global' ? null : $get('access_location_id')))->orderBy('name')->pluck('name', 'id')->all())
                    ->createOptionForm(fn (Get $get): array => [
                        TextInput::make('name')->label(__('Role Name'))->placeholder(__('e.g. Cashier'))->required()->maxLength(255)
                            ->rule(Rule::notIn(['super_admin']))
                            ->rule(fn (): Unique => Rule::unique('roles', 'name')->where('guard_name', 'web')->where('location_id', $get('access_location_id') === 'global' ? null : $get('access_location_id'))),
                        Select::make('permission_ids')->label(__('Permissions'))->multiple()->searchable()->preload()
                            ->helperText(__('Choose the permissions this role grants.'))
                            ->options(fn (): array => app(PermissionGroups::class)->localizedOptions(auth()->user())),
                    ])
                    ->createOptionModalHeading(__('Create Role'))
                    ->createOptionAction(fn (Action $action): Action => $action->authorize(fn (): bool => auth()->user()->can('create', Role::class)))
                    ->createOptionUsing(function (array $data, Get $get, ?User $record): int {
                        $actor = auth()->user();
                        $locationId = $get('access_location_id') === 'global' ? null : (int) $get('access_location_id');
                        abort_unless($actor->can('create', Role::class), 403);
                        app(UserAccessManager::class)->validateScope($locationId, $record ? $record->locations()->pluck('locations.id')->all() : ($get('location_ids') ?? []));
                        abort_unless($actor->isGlobalSuperAdmin() || ($locationId !== null && $actor->locations()->whereKey($locationId)->exists()), 403);
                        abort_if($data['name'] === 'super_admin', 403);
                        $role = app(RoleAccessManager::class)->save($actor, [
                            'name' => $data['name'], 'scope' => $locationId === null ? 'global' : 'location',
                            'location_id' => $locationId, 'permission_ids' => $data['permission_ids'] ?? [],
                        ]);

                        return $role->getKey();
                    }),
            ])->columns(2)->columnSpanFull(),
            Section::make(__('Direct permissions'))->description(__('Optional permissions granted directly in the selected location.'))->icon('heroicon-m-key')->schema([
                ViewField::make('permission_ids')->label(__('Additional Permissions'))
                    ->view('access-management::filament.forms.permission-matrix')
                    ->viewData(fn (): array => [
                        'permissionGroups' => app(PermissionGroups::class)->matrix(auth()->user()),
                        'assignmentKind' => 'direct',
                    ])->default([])->rules(['array'])
                    ->in(fn (): array => collect(app(PermissionGroups::class)->options(auth()->user()))
                        ->flatMap(fn (array $permissions): array => array_keys($permissions))->all())
                    ->live()->columnSpanFull(),
                Text::make(function (Get $get): string {
                    if ($get('access_location_id') === 'global') {
                        return __('For regular users, select an assigned location to grant panel access.');
                    }

                    return app(UserAccessManager::class)->allowsPanelAccess($get('role_ids') ?? [], $get('permission_ids') ?? [])
                        ? __('Selected permissions allow panel access in this location when the account is active.')
                        : __('This selection does not grant panel access. Choose a role or additional permission that allows viewing a panel page.');
                })->icon('heroicon-m-information-circle')->columnSpanFull(),
            ])->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Personal information'))->icon('heroicon-o-user')->columns(2)->columnSpanFull()->extraAttributes(['class' => 'fox-record-section'])->schema([
                TextEntry::make('name')->label(__('Full Name'))->weight('bold'),
                TextEntry::make('email')->label(__('Email Address'))->copyable()->icon('heroicon-o-envelope'),
                TextEntry::make('is_active')->label(__('Account status'))->badge()->color(fn (bool $state): string => $state ? 'success' : 'gray')->formatStateUsing(fn (bool $state): string => $state ? __('Active') : __('Disabled')),
                TextEntry::make('created_at')->label(__('Created at'))->dateTime()->placeholder(__('Not recorded')),
            ]),
            Section::make(__('Account security'))->icon('heroicon-o-shield-check')->columns(2)->columnSpanFull()->extraAttributes(['class' => 'fox-record-section'])->schema([
                TextEntry::make('must_change_password')->label(__('Force password change'))->badge()->color(fn (bool $state): string => $state ? 'warning' : 'success')->formatStateUsing(fn (bool $state): string => $state ? __('Required') : __('Not required')),
                TextEntry::make('last_login_at')->label(__('Last login'))->dateTime()->placeholder(__('Not recorded')),
                TextEntry::make('updated_at')->label(__('Updated at'))->dateTime()->placeholder(__('Not recorded')),
            ]),
            Section::make(__('Location access'))->icon('heroicon-o-map-pin')->columns(2)->columnSpanFull()->schema([
                TextEntry::make('locations.name')->label(__('Assigned Locations'))->badge()->placeholder(__('No locations assigned.')),
                TextEntry::make('roles.name')->label(__('Current roles'))->badge()->formatStateUsing(fn (string $state): string => __($state))->placeholder(__('No assigned role')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label(__('Name'))->searchable()->sortable(), TextColumn::make('email')->label(__('Email'))->searchable(), IconColumn::make('is_active')->label(__('Active'))->boolean(), TextColumn::make('locations.name')->label(__('Locations'))->badge(), TextColumn::make('roles.name')->badge()->label(__('Current roles')), TextColumn::make('last_login_at')->label(__('Last login'))->dateTime()->sortable()])
            ->filters([TrashedFilter::make(), TernaryFilter::make('is_active')->label(__('Active')), SelectFilter::make('locations')->label(__('Locations'))->relationship('locations', 'name'), SelectFilter::make('roles')->label(__('Roles'))->relationship('roles', 'name')])
            ->recordActions([ViewAction::make(), EditAction::make(), DeleteAction::make(), RestoreAction::make(), ForceDeleteAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords(),
                    RestoreBulkAction::make()->authorizeIndividualRecords(),
                    ForceDeleteBulkAction::make()->authorizeIndividualRecords(),
                ]),
            ]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['locations', 'roles']);
        $actor = auth()->user();

        return $actor?->isGlobalSuperAdmin() ? $query : $query->whereHas('locations', fn (Builder $q) => $q->whereKey(getPermissionsTeamId()));
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/'), 'create' => CreateUser::route('/create'), 'view' => ViewUser::route('/{record}'), 'edit' => EditUser::route('/{record}/edit')];
    }

    public static function getRelations(): array
    {
        return [LocationsRelationManager::class];
    }
}
