<?php

namespace App\Modules\AccessManagement\Policies;

use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isGlobalSuperAdmin() || $user->can('roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $this->inScope($user, $role) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isGlobalSuperAdmin() || $user->can('roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        return ! $role->isSystemRole() && $this->inScope($user, $role) && ($user->isGlobalSuperAdmin() || $user->can('roles.update'));
    }

    public function delete(User $user, Role $role): bool
    {
        return ! $role->isSystemRole() && ! $role->users()->exists() && $this->inScope($user, $role) && ($user->isGlobalSuperAdmin() || $user->can('roles.delete'));
    }

    public function assignPermissions(User $user, Role $role): bool
    {
        return ! $role->isSystemRole() && $this->inScope($user, $role) && ($user->isGlobalSuperAdmin() || $user->can('permissions.assign'));
    }

    private function inScope(User $user, Role $role): bool
    {
        return $user->isGlobalSuperAdmin() || ($role->location_id !== null
            && (int) $role->location_id === (int) getPermissionsTeamId()
            && $user->locations()->whereKey($role->location_id)->exists());
    }
}
