<?php

namespace App\Modules\AccessManagement\Policies;

use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\User;

class PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->isGlobalSuperAdmin() || $user->can('permissions.view'));
    }

    public function view(User $user, Permission $permission): bool
    {
        return $permission->guard_name === 'web' && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Permission $permission): bool
    {
        return false;
    }

    public function delete(User $user, Permission $permission): bool
    {
        return false;
    }
}
