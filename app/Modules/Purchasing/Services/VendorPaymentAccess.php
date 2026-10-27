<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use Illuminate\Database\Eloquent\Builder;

class VendorPaymentAccess
{
    public function authorize(User $actor, string $permission, ?int $locationId = null): void
    {
        abort_unless($actor->exists && ! $actor->trashed() && RequestAuthorizationFacts::actorIsActive($actor), 403);
        abort_unless(in_array($permission, [PurchasingPermissions::PAYMENTS_VIEW, PurchasingPermissions::PAYMENTS_CREATE, PurchasingPermissions::PAYMENTS_POST, PurchasingPermissions::PAYMENTS_ALLOCATE, PurchasingPermissions::PAYMENTS_REVERSE, PurchasingPermissions::PAYMENTS_VIEW_ALL_LOCATIONS], true), 403);
        RequestAuthorizationFacts::refreshPermissionRelations($actor);
        app(ModuleAccess::class)->authorize($actor, 'purchasing', $permission);
        if ($locationId !== null) {
            abort_unless(RequestAuthorizationFacts::locationIsActive($locationId), 403);
        }
        if (! $actor->isGlobalSuperAdmin()) {
            $scope = getPermissionsTeamId();
            abort_unless($scope !== null && RequestAuthorizationFacts::locationIsActive((int) $scope)
                && RequestAuthorizationFacts::actorHasLocation($actor, (int) $scope), 403);
            if ($locationId !== null && (int) $scope !== $locationId) {
                abort_unless($permission === PurchasingPermissions::PAYMENTS_VIEW, 403);
                app(ModuleAccess::class)->authorize($actor, 'purchasing', PurchasingPermissions::PAYMENTS_VIEW_ALL_LOCATIONS);
            }
        }
    }

    public function operation(User $actor, string $permission, int $locationId): void
    {
        $this->authorize($actor, PurchasingPermissions::PAYMENTS_VIEW, $locationId);
        $this->authorize($actor, $permission, $locationId);
    }

    public function scope(Builder $query, User $actor, string $column = 'location_id'): Builder
    {
        $this->authorize($actor, PurchasingPermissions::PAYMENTS_VIEW);
        if ($actor->isGlobalSuperAdmin()) {
            return $query;
        }
        if ($actor->can(PurchasingPermissions::PAYMENTS_VIEW_ALL_LOCATIONS)) {
            $this->authorize($actor, PurchasingPermissions::PAYMENTS_VIEW_ALL_LOCATIONS);

            return $query;
        }

        return $query->where($column, getPermissionsTeamId());
    }
}
