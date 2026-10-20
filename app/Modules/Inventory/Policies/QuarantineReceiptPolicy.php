<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Models\QuarantineReceipt;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;

class QuarantineReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return app(InventoryAccess::class)->allows($user, InventoryPermissions::QUARANTINE_VIEW);
    }

    public function view(User $user, QuarantineReceipt $receipt): bool
    {
        return app(InventoryAccess::class)->allows($user, InventoryPermissions::QUARANTINE_VIEW, (int) $receipt->location_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, QuarantineReceipt $receipt): bool
    {
        return false;
    }

    public function delete(User $user, QuarantineReceipt $receipt): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
