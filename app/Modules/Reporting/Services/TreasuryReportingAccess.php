<?php

namespace App\Modules\Reporting\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\Treasury\Services\TreasuryWorkspace;

class TreasuryReportingAccess extends ReportingAccess
{
    public const VIEW = 'reporting.treasury.view';

    public function authorize(User $actor, ?int $locationId = null): void
    {
        app(ModuleAccess::class)->authorize($actor, 'reporting', self::VIEW);
        app(TreasuryWorkspace::class)->accounts($actor);
    }

    /** @return array<int,string> */
    public function accounts(User $actor, ?int $locationId = null, ?int $accountId = null): array
    {
        $this->authorize($actor, $locationId);
        $query = app(TreasuryWorkspace::class)->accounts($actor);
        if ($locationId !== null) {
            $locationId === 0 ? $query->whereNull('location_id') : $query->where('location_id', $locationId);
        }
        if ($accountId !== null) {
            $query->whereKey($accountId);
        }
        $result = $query->orderBy('id')->pluck('name', 'id')->all();
        abort_if(($accountId !== null || $locationId !== null) && $result === [], 403);

        return $result;
    }

    public function branches(User $actor, ?int $locationId = null): array
    {
        $accounts = app(TreasuryWorkspace::class)->accounts($actor)->get(['location_id']);
        $this->authorize($actor, $locationId);
        $locations = Location::whereIn('id', $accounts->pluck('location_id')->filter())->orderBy('id')->pluck('name', 'id')->all();
        if ($accounts->contains(fn ($account): bool => $account->location_id === null)) {
            $locations = [0 => 'Central'] + $locations;
        }

        return $locations;
    }
}
