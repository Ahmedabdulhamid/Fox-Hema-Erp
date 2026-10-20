<?php

namespace App\Modules\Sales\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SalesAccess
{
    public function authorize(User $actor, string $permission, ?int $locationId = null): void
    {
        abort_unless($actor->exists && ! $actor->trashed()
            && User::whereKey($actor->id)->where('is_active', true)->exists(), 403);
        abort_unless(in_array($permission, SalesPermissions::all(), true), 403);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        app(ModuleAccess::class)->authorize($actor, 'sales', $permission);
        if ($locationId !== null) {
            $location = Location::whereKey($locationId)->where('type', 'branch');
            if (! in_array($permission, [SalesPermissions::VIEW, SalesPermissions::DELIVERIES_VIEW,
                SalesPermissions::INVOICES_VIEW, SalesPermissions::RETURNS_VIEW, SalesPermissions::CREDITS_VIEW,
                SalesPermissions::INVOICES_DOWNLOAD, SalesPermissions::CREDITS_DOWNLOAD], true)) {
                $location->where('is_active', true);
            }
            abort_unless($location->exists(), 403);
        }
        if (! $actor->isGlobalSuperAdmin()) {
            $scope = getPermissionsTeamId();
            abort_unless($scope !== null && ($locationId === null || (int) $scope === $locationId)
                && Location::whereKey($scope)->where('type', 'branch')->where('is_active', true)->exists()
                && $actor->locations()->whereKey($scope)->exists(), 403);
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

    public function scopeOrders(Builder $query, ?User $actor): Builder
    {
        if (! $this->allows($actor, SalesPermissions::VIEW)) {
            return $query->whereRaw('1 = 0');
        }
        $query->whereHas('location', fn (Builder $locations) => $locations->where('type', 'branch'));

        return $actor->isGlobalSuperAdmin() ? $query : $query->where('sales_orders.location_id', getPermissionsTeamId());
    }

    public function branches(?User $actor): Builder
    {
        $query = Location::where('type', 'branch')->where('is_active', true);
        if (! $this->allows($actor, SalesPermissions::VIEW)) {
            return $query->whereRaw('1 = 0');
        }

        return $actor->isGlobalSuperAdmin() ? $query : $query->whereKey(getPermissionsTeamId());
    }

    public function scopeDeliveries(Builder $query, ?User $actor): Builder
    {
        if (! $this->allows($actor, SalesPermissions::DELIVERIES_VIEW)) {
            return $query->whereRaw('1 = 0');
        }
        $query->whereHas('location', fn (Builder $locations) => $locations->where('type', 'branch'));

        return $actor->isGlobalSuperAdmin() ? $query : $query->where($query->getModel()->qualifyColumn('location_id'), getPermissionsTeamId());
    }

    public function scopeInvoices(Builder $query, ?User $actor): Builder
    {
        if (! $this->allows($actor, SalesPermissions::INVOICES_VIEW)) {
            return $query->whereRaw('1 = 0');
        }
        $query->whereHas('location', fn (Builder $locations) => $locations->where('type', 'branch'));

        return $actor->isGlobalSuperAdmin() ? $query : $query->where($query->getModel()->qualifyColumn('location_id'), getPermissionsTeamId());
    }

    public function scopeReturns(Builder $query, ?User $actor): Builder
    {
        if (! $this->allows($actor, SalesPermissions::RETURNS_VIEW)) {
            return $query->whereRaw('1 = 0');
        }
        $query->whereHas('location', fn (Builder $locations) => $locations->where('type', 'branch'));

        return $actor->isGlobalSuperAdmin() ? $query : $query->where($query->getModel()->qualifyColumn('location_id'), getPermissionsTeamId());
    }
}
