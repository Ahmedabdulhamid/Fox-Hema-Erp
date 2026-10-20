<?php

namespace App\Modules\AccessManagement\Filament\Resources\Users\Pages;

use App\Modules\AccessManagement\Filament\Resources\Users\UserResource;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\UserAccessManager;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('Create New User');
    }

    public function getSubheading(): string
    {
        return __('Add a user and assign secure location-based access.');
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('Create User'))->icon('heroicon-m-user-plus');
    }

    protected function handleRecordCreation(array $data): Model
    {
        $locations = array_map('intval', Arr::pull($data, 'location_ids', []));
        $roles = array_map('intval', Arr::pull($data, 'role_ids', []));
        $permissions = array_map('intval', Arr::pull($data, 'permission_ids', []));
        $scope = Arr::pull($data, 'access_location_id', getPermissionsTeamId() ?? 'global');
        $locationId = $scope === 'global' ? null : (int) $scope;
        app(UserAccessManager::class)->validateScope($locationId, $locations);
        $actor = auth()->user();
        abort_unless($actor->isGlobalSuperAdmin() || collect($locations)->diff($actor->locations()->pluck('locations.id'))->isEmpty(), 403);
        $globalRoles = Role::query()->whereIn('id', $roles)->whereNull('location_id');
        abort_if(($actor->isGlobalSuperAdmin() ? $globalRoles->where('name', 'super_admin') : $globalRoles)->exists(), 403);
        if (! $actor->isGlobalSuperAdmin()) {
            abort_unless(collect($permissions)->diff($actor->getAllPermissions()->pluck('id'))->isEmpty(), 403);
        }
        $data['password'] = Hash::make($data['password']);

        return DB::transaction(function () use ($data, $locations, $roles, $permissions, $locationId) {
            $user = static::getModel()::create($data);
            $user->locations()->sync($locations);
            app(UserAccessManager::class)->sync($user, $locationId, $roles, $permissions);
            app(AuditLogger::class)->record('user_created', $user, ['locations' => $locations, 'roles' => $roles, 'permissions' => $permissions, 'location_id' => $locationId]);

            return $user;
        });
    }
}
