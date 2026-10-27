<?php

namespace App\Modules\Payments\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use Illuminate\Database\Eloquent\Builder;

class PaymentsAccess
{
    public function authorize(User $actor, string $permission, ?int $locationId = null): void
    {
        abort_unless($actor->exists && ! $actor->trashed()
            && RequestAuthorizationFacts::actorIsActive($actor), 403);
        abort_unless(in_array($permission, PaymentsPermissions::all(), true), 403);
        RequestAuthorizationFacts::refreshPermissionRelations($actor);
        app(ModuleAccess::class)->authorize($actor, 'payments', $permission);
        if ($locationId !== null) {
            abort_unless(RequestAuthorizationFacts::locationIsActive($locationId), 403);
        }
        if (! $actor->isGlobalSuperAdmin()) {
            $scope = getPermissionsTeamId();
            abort_unless($scope !== null
                && RequestAuthorizationFacts::locationIsActive((int) $scope)
                && RequestAuthorizationFacts::actorHasLocation($actor, (int) $scope), 403);
            if ($locationId !== null && (int) $scope !== $locationId) {
                abort_unless($permission === PaymentsPermissions::VIEW && $actor->can(PaymentsPermissions::VIEW_ALL_LOCATIONS), 403);
                app(ModuleAccess::class)->authorize($actor, 'payments', PaymentsPermissions::VIEW_ALL_LOCATIONS);
            }
        }
    }

    public function scope(Builder $query, User $actor): Builder
    {
        $this->authorize($actor, PaymentsPermissions::VIEW);
        if ($actor->isGlobalSuperAdmin()) {
            return $query;
        }
        if ($actor->can(PaymentsPermissions::VIEW_ALL_LOCATIONS)) {
            $this->authorize($actor, PaymentsPermissions::VIEW_ALL_LOCATIONS);

            return $query;
        }

        return $query->where('location_id', getPermissionsTeamId());
    }
}
