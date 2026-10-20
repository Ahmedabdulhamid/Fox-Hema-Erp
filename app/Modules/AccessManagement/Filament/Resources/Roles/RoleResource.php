<?php

namespace App\Modules\AccessManagement\Filament\Resources\Roles;

use App\Modules\AccessManagement\Filament\Resources\Roles\Pages\CreateRole;
use App\Modules\AccessManagement\Filament\Resources\Roles\Pages\EditRole;
use App\Modules\AccessManagement\Filament\Resources\Roles\Pages\ListRoles;
use App\Modules\AccessManagement\Filament\Resources\Roles\Schemas\RoleForm;
use App\Modules\AccessManagement\Filament\Resources\Roles\Tables\RolesTable;
use App\Modules\AccessManagement\Models\Role;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $recordRouteKeyName = 'slug';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return __('Users & Permissions');
    }

    public static function getModelLabel(): string
    {
        return __('Role');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Roles');
    }

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    public static function canEdit(Model $record): bool
    {
        return ! $record->isSystemRole() && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return ! $record->isSystemRole() && parent::canDelete($record);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['location'])->withCount(['permissions', 'users']);
        $user = auth()->user();

        return $user?->isGlobalSuperAdmin() ? $query : $query->where('location_id', getPermissionsTeamId());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
