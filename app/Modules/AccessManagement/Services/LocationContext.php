<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

class LocationContext
{
    public const SESSION_KEY = 'access_management.location_id';

    public function initializeFor(User $user): ?int
    {
        $id = session()->get(self::SESSION_KEY);
        if ($id !== null && (! Location::query()->whereKey($id)->where('is_active', true)->exists() || (! $user->isGlobalSuperAdmin() && ! $user->locations()->whereKey($id)->exists()))) {
            $id = null;
        }
        if ($id === null && ! $user->isGlobalSuperAdmin()) {
            $id = $user->locations()->where('is_active', true)->value('locations.id');
        }

        return $this->setFor($user, $id === null ? null : (int) $id);
    }

    public function setFor(User $user, ?int $locationId): ?int
    {
        if ($locationId !== null) {
            $exists = Location::query()->whereKey($locationId)->where('is_active', true)->exists();
            $authorized = $user->isGlobalSuperAdmin()
                || $user->locations()->whereKey($locationId)->where('locations.is_active', true)->exists();
            if (! $exists || ! $authorized) {
                throw new AuthorizationException(__('You are not authorized to access this location.'));
            }
        } elseif (! $user->isGlobalSuperAdmin()) {
            throw new AuthorizationException(__('A valid location is required.'));
        }

        session()->put(self::SESSION_KEY, $locationId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($locationId);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        if (auth('web')->user()?->is($user)) {
            auth('web')->user()->unsetRelation('roles')->unsetRelation('permissions');
        }

        return $locationId;
    }

    public function clear(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }
}
