<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleAccessManager
{
    public function save(User $actor, array $data, ?Role $role = null): Role
    {
        abort_unless($actor->is_active && $actor->can($role ? 'update' : 'create', $role ?? Role::class), 403);
        $scope = Arr::pull($data, 'scope', 'location');
        abort_unless(in_array($scope, ['global', 'location'], true), 403);
        $locationId = $scope === 'global' ? null : (filled($data['location_id'] ?? null) ? (int) $data['location_id'] : null);
        abort_if(strtolower(trim($data['name'] ?? '')) === 'super_admin' || $role?->isSystemRole(), 403);
        if ($locationId !== null) {
            abort_unless(Location::query()->whereKey($locationId)->where('is_active', true)->exists(), 403);
        }
        abort_unless($actor->isGlobalSuperAdmin() || ($locationId !== null && $locationId === (int) getPermissionsTeamId()
            && $actor->locations()->whereKey($locationId)->exists()), 403);
        $ids = array_values(array_unique(array_map('intval', Arr::pull($data, 'permission_ids', []))));
        abort_unless(Permission::query()->where('guard_name', 'web')->whereIn('id', $ids)->count() === count($ids), 403);
        $existing = $role?->permissions()->pluck('permissions.id')->map(fn ($id): int => (int) $id)->all() ?? [];
        sort($existing);
        sort($ids);
        if ($ids !== $existing) {
            abort_unless($actor->isGlobalSuperAdmin() || ($actor->can('permissions.assign')
                && collect($ids)->diff($actor->getAllPermissions()->pluck('id'))->isEmpty()), 403);
        }

        return DB::transaction(function () use ($actor, $role, $data, $locationId, $ids): Role {
            ModuleRegistryLock::acquire();
            if ($role !== null) {
                $role = Role::query()->lockForUpdate()->findOrFail($role->id);
                abort_unless($actor->can('update', $role), 403);
                if ($role->location_id !== $locationId && DB::table('model_has_roles')->where('role_id', $role->id)->exists()) {
                    throw ValidationException::withMessages(['data.location_id' => __('An assigned role cannot change scope. Create a new role instead.')]);
                }
            }
            if (Role::query()->where('location_id', $locationId)->where('name', $data['name'])->where('guard_name', 'web')
                ->when($role, fn ($query) => $query->whereKeyNot($role->id))->exists()) {
                throw ValidationException::withMessages(['data.name' => __('This role name already exists in the selected location.')]);
            }
            $role ??= new Role;
            $role->fill(['name' => $data['name'], 'guard_name' => 'web', 'location_id' => $locationId])->save();
            $role->syncPermissions($ids);
            app(AuditLogger::class)->record('role_saved', metadata: ['role_id' => $role->id, 'location_id' => $locationId]);

            return $role;
        });
    }
}
