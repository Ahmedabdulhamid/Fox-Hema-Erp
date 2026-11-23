<?php

namespace App\Modules\Reporting\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Purchasing\Services\VendorPaymentAccess;

class PurchasingReportingAccess extends ReportingAccess
{
    public const VIEW = 'reporting.purchasing.view';

    public function authorize(User $actor, ?int $locationId = null): void
    {
        app(ModuleAccess::class)->authorize($actor, 'reporting', self::VIEW);
        foreach ([PurchasingPermissions::VIEW, PurchasingPermissions::ORDERS_VIEW, PurchasingPermissions::RECEIPTS_VIEW, PurchasingPermissions::BILLS_VIEW] as $permission) {
            app(PurchasingAccess::class)->authorize($actor, $permission, $locationId);
        }
        app(VendorPaymentAccess::class)->authorize($actor, PurchasingPermissions::PAYMENTS_VIEW, $locationId);
    }

    /** @return array<int,string> */
    public function branches(User $actor, ?int $locationId = null): array
    {
        $this->authorize($actor, $locationId);

        return Location::query()->where('is_active', true)
            ->when(! $actor->isGlobalSuperAdmin(), fn ($query) => $query->whereKey(getPermissionsTeamId()))
            ->when($locationId !== null, fn ($query) => $query->whereKey($locationId))
            ->orderBy('id')->pluck('name', 'id')->all();
    }
}
