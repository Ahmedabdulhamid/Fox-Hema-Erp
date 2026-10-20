<?php

namespace App\Modules\Inventory\Services;

class InventoryPermissions
{
    public const BALANCES = 'inventory.stock_balances.view';

    public const MOVEMENTS = 'inventory.stock_movements.view';

    public const COST = 'inventory.stock_movements.view_cost';

    public const POST = 'inventory.post';

    public const ALLOCATIONS_VIEW = 'inventory.size_allocations.view';

    public const ALLOCATIONS_MANAGE = 'inventory.size_allocations.manage';

    public const ALLOCATIONS_POST = 'inventory.size_allocations.post';

    public const QUARANTINE_VIEW = 'inventory.quarantine.view';

    public const QUARANTINE_INSPECT = 'inventory.quarantine.inspect';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::BALANCES, self::MOVEMENTS, self::COST, self::POST, self::ALLOCATIONS_VIEW, self::ALLOCATIONS_MANAGE, self::ALLOCATIONS_POST,
            self::QUARANTINE_VIEW, self::QUARANTINE_INSPECT];
    }
}
