<?php

namespace App\Modules\AccessManagement\Policies;

use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;

class ModulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->isGlobalSuperAdmin() || $user->can('modules.view'));
    }

    public function view(User $user, Module $module): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Module $module): bool
    {
        return $this->viewAny($user) && ($user->isGlobalSuperAdmin() || $user->can('modules.update'));
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, Module $module): bool
    {
        return false;
    }
}
