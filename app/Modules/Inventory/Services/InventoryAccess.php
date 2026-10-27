<?php

namespace App\Modules\Inventory\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Inventory\Data\SystemPostingContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InventoryAccess
{
    public function authorize(User $actor, string $permission, ?int $locationId = null): void
    {
        abort_unless($actor->exists && ! $actor->trashed() && RequestAuthorizationFacts::actorIsActive($actor), 403);
        app(ModuleAccess::class)->authorize($actor, 'inventory', $permission);
        if (! $actor->isGlobalSuperAdmin()) {
            $scope = getPermissionsTeamId();
            abort_unless($scope !== null && ($locationId === null || (int) $scope === $locationId)
                && RequestAuthorizationFacts::locationIsActive((int) $scope)
                && RequestAuthorizationFacts::actorHasLocation($actor, (int) $scope), 403);
        }
    }

    public function allows(?User $actor, string $permission, ?int $locationId = null): bool
    {
        if ($actor === null) {
            return false;
        }
        try {
            $this->authorize($actor, $permission, $locationId);

            return true;
        } catch (AuthorizationException|HttpException $exception) {
            return false;
        }
    }

    public function scope(Builder $query, ?User $actor, string $permission): Builder
    {
        if (! $this->allows($actor, $permission)) {
            return $query->whereRaw('1 = 0');
        }

        return $actor->isGlobalSuperAdmin() ? $query : $query->where($query->getModel()->qualifyColumn('location_id'), getPermissionsTeamId());
    }

    public function locations(?User $actor, string $permission): Builder
    {
        $query = Location::query();
        if (! $this->allows($actor, $permission)) {
            return $query->whereRaw('1 = 0');
        }

        return $actor->isGlobalSuperAdmin() ? $query : $query->whereKey(getPermissionsTeamId());
    }

    public function authorizePosting(User|SystemPostingContext $actor, int $locationId): void
    {
        abort_unless(Location::whereKey($locationId)->where('is_active', true)->exists(), 403);
        if ($actor instanceof SystemPostingContext) {
            abort_unless(app()->runningInConsole() && preg_match('/^[a-z][a-z0-9_.-]{0,79}$/', $actor->name)
                && in_array($actor->name, config('inventory.system_actors', []), true)
                && app(ModuleAccess::class)->isActive('inventory'), 403);

            return;
        }
        // Background user operations resolve permissions at the explicit target, then restore context.
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        try {
            $registrar->setPermissionsTeamId($locationId);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            $this->authorize($actor, InventoryPermissions::POST, $locationId);
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
