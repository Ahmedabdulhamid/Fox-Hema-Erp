<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Models\SizeAllocation;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;

class SizeAllocationPolicy
{
    public function viewAny(User $user): bool
    {
        return app(InventoryAccess::class)->allows($user, InventoryPermissions::ALLOCATIONS_VIEW);
    }

    public function view(User $user, SizeAllocation $record): bool
    {
        return app(InventoryAccess::class)->allows($user, InventoryPermissions::ALLOCATIONS_VIEW, (int) $record->location_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && app(InventoryAccess::class)->allows($user, InventoryPermissions::ALLOCATIONS_MANAGE);
    }

    public function update(User $user, SizeAllocation $record): bool
    {
        return $record->status === 'draft' && $this->view($user, $record)
            && app(InventoryAccess::class)->allows($user, InventoryPermissions::ALLOCATIONS_MANAGE, (int) $record->location_id);
    }

    public function post(User $user, SizeAllocation $record): bool
    {
        return $record->status === 'draft' && $this->view($user, $record)
            && app(InventoryAccess::class)->allows($user, InventoryPermissions::ALLOCATIONS_POST, (int) $record->location_id)
            && app(InventoryAccess::class)->allows($user, InventoryPermissions::POST, (int) $record->location_id);
    }

    public function delete(User $user, SizeAllocation $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
