<?php

namespace App\Modules\AccessManagement\Policies;

use App\Modules\AccessManagement\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isGlobalSuperAdmin() || $actor->can('users.view');
    }

    public function view(User $actor, User $target): bool
    {
        return $this->inScope($actor, $target) && $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $actor->isGlobalSuperAdmin() || $actor->can('users.create');
    }

    public function update(User $actor, User $target): bool
    {
        return ! $this->protected($actor, $target) && $this->inScope($actor, $target) && ($actor->isGlobalSuperAdmin() || $actor->can('users.update'));
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->isNot($target) && $this->inScope($actor, $target) && ($actor->isGlobalSuperAdmin() || $actor->can('users.delete')) && ! $target->isGlobalSuperAdmin();
    }

    public function deleteAny(User $actor): bool
    {
        return $actor->isGlobalSuperAdmin() || $actor->can('users.delete');
    }

    public function restore(User $actor, User $target): bool
    {
        return $this->delete($actor, $target);
    }

    public function restoreAny(User $actor): bool
    {
        return $this->deleteAny($actor);
    }

    public function forceDelete(User $actor, User $target): bool
    {
        return $this->delete($actor, $target);
    }

    public function forceDeleteAny(User $actor): bool
    {
        return $this->deleteAny($actor);
    }

    public function revokeSessions(User $actor, User $target): bool
    {
        return $this->inScope($actor, $target) && ($actor->isGlobalSuperAdmin() || $actor->can('sessions.revoke'));
    }

    private function protected(User $actor, User $target): bool
    {
        return $target->isGlobalSuperAdmin() && ! $actor->isGlobalSuperAdmin();
    }

    private function inScope(User $actor, User $target): bool
    {
        return $actor->isGlobalSuperAdmin() || $target->locations()->whereKey(getPermissionsTeamId())->exists();
    }
}
