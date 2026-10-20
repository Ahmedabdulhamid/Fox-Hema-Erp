<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\Inventory\Services\InventoryPermissions;

class StockMovementPolicy extends StockBalancePolicy
{
    protected string $permission = InventoryPermissions::MOVEMENTS;
}
