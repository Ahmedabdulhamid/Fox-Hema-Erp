<?php

namespace App\Modules\StockTransfer\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\StockTransfer\Models\StockTransfer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StockTransferAccess
{
    public function authorize(User $actor, string $permission, int ...$locationIds): void
    {
        abort_unless($actor->exists && ! $actor->trashed() && RequestAuthorizationFacts::actorIsActive($actor), 403);
        $locations = array_values(array_unique($locationIds));
        if ($locations === []) {
            $this->authorizeAt($actor, $permission, getPermissionsTeamId());

            return;
        }

        foreach ($locations as $locationId) {
            $this->authorizeAt($actor, $permission, $locationId);
        }
    }

    public function allows(?User $actor, string $permission, ?int $locationId = null): bool
    {
        if ($actor === null) {
            return false;
        }
        try {
            $this->authorize($actor, $permission, ...($locationId === null ? [] : [$locationId]));

            return true;
        } catch (AuthorizationException|HttpException $exception) {
            return false;
        }
    }

    /** @return Builder<Location> */
    public function locations(?User $actor, string $permission): Builder
    {
        if ($actor === null || ! $this->allows($actor, $permission)) {
            return Location::query()->whereRaw('1 = 0');
        }

        if ($actor->isGlobalSuperAdmin()) {
            return Location::query()->where('is_active', true);
        }

        $locationIds = Location::query()->where('is_active', true)->pluck('id')
            ->filter(fn ($id): bool => $this->allows($actor, $permission, (int) $id))
            ->values();

        return Location::query()->whereKey($locationIds);
    }

    public function scope(Builder $query, ?User $actor, string $permission): Builder
    {
        $locationIds = $this->locations($actor, $permission)->pluck('id');

        return $query->whereIn($query->getModel()->qualifyColumn('from_location_id'), $locationIds)
            ->whereIn($query->getModel()->qualifyColumn('to_location_id'), $locationIds);
    }

    public function authorizeTransfer(User $actor, string $permission, StockTransfer $transfer, ?int $permissionLocationId = null): void
    {
        $this->authorize($actor, StockTransferPermissions::VIEW, (int) $transfer->from_location_id, (int) $transfer->to_location_id);
        $this->authorize($actor, $permission, ...($permissionLocationId === null
            ? [(int) $transfer->from_location_id, (int) $transfer->to_location_id]
            : [$permissionLocationId]));
    }

    private function authorizeAt(User $actor, string $permission, ?int $locationId): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        try {
            $registrar->setPermissionsTeamId($locationId);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            app(ModuleAccess::class)->authorize($actor, 'stock_transfers', $permission);
            if ($locationId !== null) {
                abort_unless(RequestAuthorizationFacts::locationIsActive($locationId), 403);
            }
            if (! $actor->isGlobalSuperAdmin()) {
                abort_unless($locationId !== null && RequestAuthorizationFacts::actorHasLocation($actor, $locationId), 403);
            }
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
