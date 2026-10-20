<?php

namespace App\Modules\AccessManagement\Policies;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;

class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isGlobalSuperAdmin() || $user->can('locations.view');
    }

    public function view(User $user, Location $location): bool
    {
        return $user->isGlobalSuperAdmin() || ($this->viewAny($user) && $user->locations()->whereKey($location)->exists());
    }

    public function create(User $user): bool
    {
        return $user->isGlobalSuperAdmin() || $user->can('locations.create');
    }

    public function update(User $user, Location $location): bool
    {
        return $user->isGlobalSuperAdmin() || ($user->can('locations.update') && $user->locations()->whereKey($location)->exists());
    }

    public function delete(User $user, Location $location): bool
    {
        return $this->update($user, $location) && ! $location->users()->exists() && ! $location->roles()->exists();
    }
}
