<?php

namespace App\Modules\Reporting\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\StockTransfer\Services\StockTransferAccess;
use App\Modules\StockTransfer\Services\StockTransferPermissions;

class StockTransferReportingAccess extends ReportingAccess
{
    public const VIEW = 'reporting.stock_transfers.view';

    public function authorize(User $actor, ?int $locationId = null): void
    {
        app(ModuleAccess::class)->authorize($actor, 'reporting', self::VIEW);
        app(StockTransferAccess::class)->authorize($actor, StockTransferPermissions::VIEW, ...($locationId === null ? [] : [$locationId]));
    }

    /** @return array<int,string> */
    public function branches(User $actor, ?int $locationId = null): array
    {
        $this->authorize($actor, $locationId);

        return app(StockTransferAccess::class)->locations($actor, StockTransferPermissions::VIEW)
            ->when($locationId !== null, fn ($query) => $query->whereKey($locationId))->orderBy('id')->pluck('name', 'id')->all();
    }
}
