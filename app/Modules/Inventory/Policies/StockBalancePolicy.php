<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use Illuminate\Database\Eloquent\Model;

class StockBalancePolicy
{
    protected string $permission = InventoryPermissions::BALANCES;

    public function viewAny(User $user): bool
    {
        return app(InventoryAccess::class)->allows($user, $this->permission);
    }

    public function view(User $user, Model $record): bool
    {
        return app(InventoryAccess::class)->allows($user, $this->permission, (int) $record->location_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }
}
