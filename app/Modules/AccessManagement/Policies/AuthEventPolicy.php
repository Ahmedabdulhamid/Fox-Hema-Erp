<?php

namespace App\Modules\AccessManagement\Policies;

use App\Modules\AccessManagement\Models\AuthEvent;
use App\Modules\AccessManagement\Models\User;

class AuthEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isGlobalSuperAdmin() || $user->can('auth_events.view');
    }

    public function view(User $user, AuthEvent $event): bool
    {
        return $this->viewAny($user) && ($user->isGlobalSuperAdmin() || (int) $event->location_id === (int) getPermissionsTeamId());
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuthEvent $event): bool
    {
        return false;
    }

    public function delete(User $user, AuthEvent $event): bool
    {
        return false;
    }
}
