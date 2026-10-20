<?php

namespace App\Modules\Purchasing\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Purchasing\Services\PurchasingPermissions;

class VendorBillPolicy
{
    public function viewAny(User $user): bool
    {
        return app(PurchasingAccess::class)->allows($user, PurchasingPermissions::BILLS_VIEW);
    }

    public function view(User $user, VendorBill $bill): bool
    {
        return $bill->purchaseOrder !== null && (int) $bill->supplier_id === (int) $bill->purchaseOrder->supplier_id
            && app(PurchasingAccess::class)->allows($user, PurchasingPermissions::BILLS_VIEW, (int) $bill->purchaseOrder->location_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && app(PurchasingAccess::class)->allows($user, PurchasingPermissions::BILLS_CREATE)
            && app(PurchasingAccess::class)->allows($user, PurchasingPermissions::ORDERS_VIEW)
            && app(PurchasingAccess::class)->allows($user, PurchasingPermissions::RECEIPTS_VIEW);
    }

    public function update(User $user, VendorBill $bill): bool
    {
        return $this->operation($user, $bill, PurchasingPermissions::BILLS_UPDATE)
            && app(PurchasingAccess::class)->allows($user, PurchasingPermissions::ORDERS_VIEW, (int) $bill->purchaseOrder->location_id)
            && app(PurchasingAccess::class)->allows($user, PurchasingPermissions::RECEIPTS_VIEW, (int) $bill->purchaseOrder->location_id);
    }

    public function post(User $user, VendorBill $bill): bool
    {
        return $this->operation($user, $bill, PurchasingPermissions::BILLS_POST);
    }

    public function cancel(User $user, VendorBill $bill): bool
    {
        return $this->operation($user, $bill, PurchasingPermissions::BILLS_CANCEL);
    }

    public function download(User $user, VendorBill $bill): bool
    {
        return $bill->status === 'posted' && $this->view($user, $bill);
    }

    public function delete(User $user, VendorBill $bill): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function operation(User $user, VendorBill $bill, string $permission): bool
    {
        return $bill->status === 'draft' && $this->view($user, $bill)
            && app(PurchasingAccess::class)->allows($user, $permission, (int) $bill->purchaseOrder->location_id);
    }
}
