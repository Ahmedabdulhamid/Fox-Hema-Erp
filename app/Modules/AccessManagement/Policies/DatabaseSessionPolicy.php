<?php

namespace App\Modules\AccessManagement\Policies;

use App\Modules\AccessManagement\Models\DatabaseSession;
use App\Modules\AccessManagement\Models\User;

class DatabaseSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isGlobalSuperAdmin() || $user->can('sessions.view');
    }

    public function view(User $user, DatabaseSession $session): bool
    {
        return DatabaseSession::query()->visibleTo($user)->whereKey($session->id)->exists();
    }

    public function delete(User $user, DatabaseSession $session): bool
    {
        return $this->view($user, $session) && ($user->isGlobalSuperAdmin() || $user->is($session->user) || $user->can('sessions.revoke'));
    }
}
