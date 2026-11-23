<?php

namespace App\Modules\Reporting\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;

class InventoryReportingAccess extends ReportingAccess
{
    public const VIEW = 'reporting.inventory.view';

    public function authorize(User $actor, ?int $locationId = null): void
    {
        app(ModuleAccess::class)->authorize($actor, 'reporting', self::VIEW);
        foreach ([InventoryPermissions::BALANCES, InventoryPermissions::MOVEMENTS] as $permission) {
            app(InventoryAccess::class)->authorize($actor, $permission, $locationId);
        }
    }

    public function branches(User $actor, ?int $locationId = null): array
    {
        $this->authorize($actor, $locationId);

        return app(InventoryAccess::class)->locations($actor, InventoryPermissions::BALANCES)
            ->when($locationId !== null, fn ($query) => $query->whereKey($locationId))
            ->orderBy('id')->pluck('name', 'id')->all();
    }

    public function costs(User $actor, ?int $locationId = null): bool
    {
        return app(ModuleAccess::class)->isActive('inventory_costing')
            && app(InventoryAccess::class)->allows($actor, InventoryPermissions::COST, $locationId);
    }
}
