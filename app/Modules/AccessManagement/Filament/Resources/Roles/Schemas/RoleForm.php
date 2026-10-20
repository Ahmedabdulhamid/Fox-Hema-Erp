<?php

namespace App\Modules\AccessManagement\Filament\Resources\Roles\Schemas;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Services\PermissionGroups;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Role details'))->description(__('Define the role name and where it can be used.'))->icon('heroicon-m-shield-check')->schema([
                TextInput::make('name')->label(__('Role Name'))->placeholder(__('e.g. Cashier'))->required()->maxLength(255)->disabled(fn ($record) => $record?->isSystemRole() ?? false),
                Select::make('scope')->label(__('Scope'))->options(['location' => __('Specific location'), 'global' => __('Reusable global role')])->helperText(__('A global role can be reused, but must be assigned to the user in each location.'))->default('location')->required()->live()->disabled(fn ($record) => $record?->isSystemRole() ?? false)->visible(fn (): bool => auth()->user()->isGlobalSuperAdmin()),
                Select::make('location_id')->label(__('Location'))->placeholder(__('Select a location'))
                    ->required(fn (Get $get): bool => ! auth()->user()->isGlobalSuperAdmin() || $get('scope') !== 'global')
                    ->visible(fn (Get $get): bool => ! auth()->user()->isGlobalSuperAdmin() || $get('scope') !== 'global')
                    ->options(function (): array {
                        $user = auth()->user();
                        $query = Location::query()->where('is_active', true)->orderBy('name');
                        if (! $user->isGlobalSuperAdmin()) {
                            $query->whereIn('id', $user->locations()->select('locations.id'));
                        }

                        return $query->pluck('name', 'id')->all();
                    })->searchable()->preload()->disabled(fn ($record) => $record?->isSystemRole() ?? false),
            ])->columns(2)->columnSpanFull(),
            Section::make(__('Permissions'))->description(__('Choose exactly what users with this role are allowed to do.'))->icon('heroicon-m-key')->schema([
                ViewField::make('permission_ids')->view('access-management::filament.forms.permission-matrix')->viewData(fn (): array => ['permissionGroups' => self::permissionGroups()])->default([]),
            ])->columnSpanFull(),
        ]);
    }

    private static function permissionGroups(): array
    {
        $groups = app(PermissionGroups::class)->options(auth()->user());

        return collect($groups)->map(fn (array $permissions): array => collect($permissions)->map(fn (string $name, int $id): array => [
            'id' => $id, 'name' => $name, 'label' => __($name),
        ])->values()->all())->all();
    }
}
