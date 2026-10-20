<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class UserAccessManager
{
    /** @return array{role_ids: array, permission_ids: array} */
    public function assignments(User $user, ?int $locationId): array
    {
        $base = ['model_type' => $user->getMorphClass(), 'model_id' => $user->getKey(), 'location_id' => $locationId];

        return [
            'role_ids' => DB::table(config('permission.table_names.model_has_roles'))->where($base)->pluck('role_id')->all(),
            'permission_ids' => DB::table(config('permission.table_names.model_has_permissions'))->where($base)->pluck('permission_id')->all(),
        ];
    }

    public function validateScope(?int $locationId, array $locationIds): void
    {
        $actor = auth()->user();
        abort_unless($actor->isGlobalSuperAdmin() || ($locationId !== null && $locationId === (int) getPermissionsTeamId()), 403);
        if ($locationId !== null && (! in_array($locationId, array_map('intval', $locationIds), true) || ! Location::query()->whereKey($locationId)->where('is_active', true)->exists())) {
            throw ValidationException::withMessages(['data.access_location_id' => __('Choose an active location assigned to this user.')]);
        }
    }

    public function formData(User $user, array $data): array
    {
        $data['location_ids'] = $user->locations()->pluck('locations.id')->all();
        $locationId = getPermissionsTeamId();
        if (auth()->user()->isGlobalSuperAdmin() && ! in_array($locationId, $data['location_ids'])) {
            $locationId = $user->locations()->where('is_active', true)->value('locations.id');
        }
        $data['access_location_id'] = $locationId ?? 'global';

        return array_replace($data, $this->assignments($user, $locationId));
    }

    public function allowsPanelAccess(array $roleIds, array $permissionIds): bool
    {
        return Permission::query()->where('guard_name', 'web')->whereIn('id', $permissionIds)->whereIn('name', User::PANEL_PERMISSIONS)->exists()
            || Role::query()->where('guard_name', 'web')->whereIn('id', $roleIds)
                ->whereHas('permissions', fn ($query) => $query->where('guard_name', 'web')->whereIn('name', User::PANEL_PERMISSIONS))->exists();
    }

    public function sync(User $user, ?int $locationId, array $roleIds, array $permissionIds): void
    {
        DB::transaction(function () use ($user, $locationId, $roleIds, $permissionIds): void {
            $actor = auth('web')->user();
            abort_unless($actor instanceof User && $actor->is_active, 403);
            abort_unless($actor->can($user->wasRecentlyCreated ? 'create' : 'update', $user->wasRecentlyCreated ? User::class : $user), 403);
            ModuleRegistryLock::acquire();
            $this->validateScope($locationId, $user->locations()->pluck('locations.id')->all());
            $roles = Role::query()->whereIn('id', $roleIds)->where('guard_name', 'web')
                ->where(fn ($query) => $query->whereNull('location_id')->orWhere('location_id', $locationId))->with('permissions')->get();
            abort_unless($roles->count() === count(array_unique($roleIds)), 403);
            $existing = $this->assignments($user, $locationId);
            $changed = collect($roleIds)->sort()->values()->all() !== collect($existing['role_ids'])->map(fn ($id): int => (int) $id)->sort()->values()->all()
                || collect($permissionIds)->sort()->values()->all() !== collect($existing['permission_ids'])->map(fn ($id): int => (int) $id)->sort()->values()->all();
            abort_unless(! $changed || $actor->isGlobalSuperAdmin() || $actor->can('permissions.assign'), 403);
            $removedSystemRole = Role::query()->whereIn('id', array_diff($existing['role_ids'], $roleIds))->whereNull('location_id')->where('name', 'super_admin')->where('guard_name', 'web')->exists();
            if ($removedSystemRole) {
                app(SuperAdminProtection::class)->ensureCanRemove($user);
            }
            $systemRoleIds = $roles->where('name', 'super_admin')->pluck('id');
            abort_unless($systemRoleIds->diff($existing['role_ids'])->isEmpty(), 403);
            abort_if($locationId !== null && $systemRoleIds->isNotEmpty(), 403);
            abort_unless(Permission::query()->whereIn('id', $permissionIds)->where('guard_name', 'web')->count() === count(array_unique($permissionIds)), 403);
            if (! $actor->isGlobalSuperAdmin()) {
                $delegated = collect($permissionIds)->merge($roles->flatMap(fn (Role $role) => $role->permissions->pluck('id')));
                abort_unless($delegated->diff($actor->getAllPermissions()->pluck('id'))->isEmpty(), 403);
            }
            if ($actor->is($user)) {
                abort_unless(collect($roleIds)->diff($existing['role_ids'])->isEmpty() && collect($permissionIds)->diff($existing['permission_ids'])->isEmpty(), 403);
            }
            $originalScope = getPermissionsTeamId();
            try {
                app(PermissionRegistrar::class)->setPermissionsTeamId($locationId);
                $user->unsetRelation('roles')->unsetRelation('permissions');
                $user->syncRoles($roleIds);
                $user->syncPermissions($permissionIds);
            } finally {
                app(PermissionRegistrar::class)->setPermissionsTeamId($originalScope);
                $user->unsetRelation('roles')->unsetRelation('permissions');
            }
        });
    }
}
