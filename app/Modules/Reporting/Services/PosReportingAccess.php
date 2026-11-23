<?php

namespace App\Modules\Reporting\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\POS\Services\PosAccess;
use App\Modules\POS\Services\PosPermissions;

class PosReportingAccess extends ReportingAccess
{
    public const VIEW = 'reporting.pos.view';

    public function authorize(User $actor, ?int $locationId = null): void
    {
        app(ModuleAccess::class)->authorize($actor, 'reporting', self::VIEW);
        $locations = app(PosAccess::class)->branchIds($actor, PosPermissions::VIEW);
        abort_if($locationId !== null && ! in_array($locationId, $locations, true), 403);
    }

    public function branches(User $actor, ?int $locationId = null): array
    {
        $this->authorize($actor, $locationId);

        return Location::whereIn('id', app(PosAccess::class)->branchIds($actor, PosPermissions::VIEW))
            ->when($locationId !== null, fn ($q) => $q->whereKey($locationId))->orderBy('id')->pluck('name', 'id')->all();
    }

    public function cashier(User $actor, ?int $cashierId = null): ?int
    {
        if (! $actor->isGlobalSuperAdmin() && ! app(PosAccess::class)->can($actor, PosPermissions::SHIFTS_APPROVE)) {
            abort_if($cashierId !== null && $cashierId !== (int) $actor->id, 403);

            return (int) $actor->id;
        }

        return $cashierId;
    }
}
