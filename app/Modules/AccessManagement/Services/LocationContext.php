<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\BackupRestore\Services\BackupAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\PermissionRegistrar;

class LocationContext
{
    public const SESSION_KEY = 'access_management.location_id';

    public function scopeLocationQuery(Builder $query, User $actor, string $permission): Builder
    {
        if (! RequestAuthorizationFacts::actorIsActive($actor)) {
            return $query->whereRaw('1 = 0');
        }
        if ($actor->isGlobalSuperAdmin()) {
            return $query;
        }

        $locationId = getPermissionsTeamId();
        RequestAuthorizationFacts::refreshPermissionRelations($actor);
        if ($locationId === null || ! RequestAuthorizationFacts::locationIsActive((int) $locationId)
            || ! RequestAuthorizationFacts::actorHasLocation($actor, (int) $locationId)
            || ! $actor->can($permission)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereKey($locationId);
    }

    public function initializeFor(User $user): ?int
    {
        $storedLocation = session()->get(self::SESSION_KEY);
        $isGlobalScope = $storedLocation === 'global';
        $id = $isGlobalScope || $storedLocation === null ? null : (int) $storedLocation;
        if ($id !== null && (! RequestAuthorizationFacts::locationIsActive((int) $id) || (! $user->isGlobalSuperAdmin() && ! RequestAuthorizationFacts::actorHasLocation($user, (int) $id)))) {
            session()->forget(self::SESSION_KEY);
            $id = null;
            $isGlobalScope = false;
        }
        if ($id === null && ! $isGlobalScope) {
            $id = $user->isGlobalSuperAdmin()
                ? Location::query()->where('type', 'branch')->where('is_active', true)->orderBy('id')->value('id')
                : $user->locations()->where('is_active', true)->value('locations.id');
        }

        try {
            return $this->setFor($user, $id === null ? null : (int) $id);
        } catch (AuthorizationException $exception) {
            session()->forget(self::SESSION_KEY);
            $this->clear();

            throw $exception;
        }
    }

    public function setFor(User $user, ?int $locationId): ?int
    {
        if ($locationId !== null) {
            $exists = RequestAuthorizationFacts::locationIsActive($locationId);
            $authorized = $user->isGlobalSuperAdmin()
                || ($exists && RequestAuthorizationFacts::actorHasLocation($user, $locationId));
            if (! $exists || ! $authorized) {
                throw new AuthorizationException(__('You are not authorized to access this location.'));
            }
        } elseif (! $user->isGlobalSuperAdmin() && ! app(BackupAccess::class)->allowsPanel($user)) {
            throw new AuthorizationException(__('A valid location is required.'));
        }

        session()->put(self::SESSION_KEY, $locationId ?? 'global');
        app(PermissionRegistrar::class)->setPermissionsTeamId($locationId);
        RequestAuthorizationFacts::refreshPermissionRelations($user);
        if (auth('web')->user()?->is($user)) {
            RequestAuthorizationFacts::refreshPermissionRelations(auth('web')->user());
        }

        return $locationId;
    }

    public function clear(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }
}
