<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PurchasingAccess
{
    public function authorize(User $actor, string $permission, ?int $locationId = null): void
    {
        abort_unless($actor->exists && ! $actor->trashed()
            && User::whereKey($actor->id)->where('is_active', true)->exists(), 403);
        abort_unless(in_array($permission, PurchasingPermissions::all(), true), 403);
        app(ModuleAccess::class)->authorize($actor, 'purchasing', $permission);
        if (! $actor->isGlobalSuperAdmin()) {
            $location = getPermissionsTeamId();
            abort_unless($location !== null && ($locationId === null || (int) $location === $locationId)
                && Location::whereKey($location)->where('is_active', true)->exists()
                && $actor->locations()->whereKey($location)->exists(), 403);
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
        if (! $this->allows($actor, PurchasingPermissions::ORDERS_VIEW)) {
            return $query->whereRaw('1 = 0');
        }

        return $actor->isGlobalSuperAdmin() ? $query : $query->where('purchase_orders.location_id', getPermissionsTeamId());
    }

    public function scopeBills(Builder $query, ?User $actor): Builder
    {
        if (! $this->allows($actor, PurchasingPermissions::BILLS_VIEW)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('purchaseOrder', fn (Builder $orders): Builder => $actor->isGlobalSuperAdmin()
            ? $orders : $orders->where('location_id', getPermissionsTeamId()));
    }

    public function locations(?User $actor): Builder
    {
        $query = Location::where('is_active', true);
        if (! $this->allows($actor, PurchasingPermissions::ORDERS_VIEW)) {
            return $query->whereRaw('1 = 0');
        }

        return $actor->isGlobalSuperAdmin() ? $query : $query->whereKey(getPermissionsTeamId());
    }

    public function scopeReceipts(Builder $query, ?User $actor): Builder
    {
        if (! $this->allows($actor, PurchasingPermissions::RECEIPTS_VIEW)) {
            return $query->whereRaw('1 = 0');
        }
        $query->whereHas('purchaseOrder', fn (Builder $orders): Builder => $orders
            ->whereColumn('purchase_orders.location_id', 'purchase_receipts.location_id'));

        return $actor->isGlobalSuperAdmin() ? $query : $query->where('purchase_receipts.location_id', getPermissionsTeamId());
    }

    public function receiptLocations(?User $actor): Builder
    {
        $query = Location::where('is_active', true);
        if (! $this->allows($actor, PurchasingPermissions::RECEIPTS_VIEW)) {
            return $query->whereRaw('1 = 0');
        }

        return $actor->isGlobalSuperAdmin() ? $query : $query->whereKey(getPermissionsTeamId());
    }
}
