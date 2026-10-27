<?php

namespace App\Modules\Treasury\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use Illuminate\Database\Eloquent\Builder;

class TreasuryAccess
{
    public function authorize(User $actor, string $permission, ?int $locationId): void
    {
        abort_unless($actor->exists && ! $actor->trashed()
            && RequestAuthorizationFacts::actorIsActive($actor), 403);
        abort_unless(in_array($permission, TreasuryPermissions::all(), true), 403);
        RequestAuthorizationFacts::refreshPermissionRelations($actor);
        $modules = app(ModuleAccess::class);
        $modules->authorize($actor, 'treasury', $permission);
        if ($locationId !== null) {
            abort_unless(RequestAuthorizationFacts::locationIsActive($locationId), 403);
        }
        if ($actor->isGlobalSuperAdmin()) {
            return;
        }
        $scope = getPermissionsTeamId();
        abort_unless($scope !== null && RequestAuthorizationFacts::locationIsActive((int) $scope)
            && RequestAuthorizationFacts::actorHasLocation($actor, (int) $scope), 403);
        if ($locationId === null || (int) $scope !== $locationId) {
            $read = in_array($permission, [TreasuryPermissions::VIEW, TreasuryPermissions::BALANCES], true);
            $modules->authorize($actor, 'treasury', $read
                ? TreasuryPermissions::VIEW_ALL_LOCATIONS : TreasuryPermissions::OPERATE_ALL_LOCATIONS);
        }
    }

    public function operation(User $actor, string $permission, ?int $locationId): void
    {
        $this->authorize($actor, TreasuryPermissions::VIEW, $locationId);
        $this->authorize($actor, $permission, $locationId);
    }

    public function scope(Builder $query, User $actor): Builder
    {
        $scope = getPermissionsTeamId();
        $this->authorize($actor, TreasuryPermissions::VIEW, $scope === null ? null : (int) $scope);
        if ($actor->isGlobalSuperAdmin()) {
            return $query;
        }
        if ($actor->can(TreasuryPermissions::VIEW_ALL_LOCATIONS)) {
            $this->authorize($actor, TreasuryPermissions::VIEW_ALL_LOCATIONS, $scope === null ? null : (int) $scope);

            return $query;
        }

        return $query->where('location_id', $scope);
    }
}
