<?php

namespace App\Modules\Purchasing\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Purchasing\Services\PurchasingPermissions;

class PurchaseReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return app(PurchasingAccess::class)->allows($user, PurchasingPermissions::RECEIPTS_VIEW);
    }

    public function view(User $user, PurchaseReceipt $receipt): bool
    {
        return app(PurchasingAccess::class)->allows($user, PurchasingPermissions::RECEIPTS_VIEW, (int) $receipt->location_id)
            && (int) $receipt->purchaseOrder?->location_id === (int) $receipt->location_id;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && app(PurchasingAccess::class)->allows($user, PurchasingPermissions::RECEIPTS_CREATE);
    }

    public function update(User $user, PurchaseReceipt $receipt): bool
    {
        return $this->operation($user, $receipt, PurchasingPermissions::RECEIPTS_UPDATE);
    }

    public function post(User $user, PurchaseReceipt $receipt): bool
    {
        return $this->operation($user, $receipt, PurchasingPermissions::RECEIPTS_POST)
            && app(ModuleAccess::class)->isActive('inventory_costing')
            && app(InventoryAccess::class)->allows($user, InventoryPermissions::POST, (int) $receipt->location_id);
    }

    public function cancel(User $user, PurchaseReceipt $receipt): bool
    {
        return $this->operation($user, $receipt, PurchasingPermissions::RECEIPTS_CANCEL);
    }

    public function delete(User $user, PurchaseReceipt $receipt): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function operation(User $user, PurchaseReceipt $receipt, string $permission): bool
    {
        return $receipt->status === 'draft' && $this->view($user, $receipt)
            && app(PurchasingAccess::class)->allows($user, $permission, (int) $receipt->location_id);
    }
}
