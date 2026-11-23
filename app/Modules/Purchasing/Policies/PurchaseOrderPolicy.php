<?php

namespace App\Modules\Purchasing\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Purchasing\Services\PurchasingPermissions;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return app(PurchasingAccess::class)->allows($user, PurchasingPermissions::ORDERS_VIEW);
    }

    public function view(User $user, PurchaseOrder $order): bool
    {
        return app(PurchasingAccess::class)->allows($user, PurchasingPermissions::ORDERS_VIEW, (int) $order->location_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && app(PurchasingAccess::class)->allows($user, PurchasingPermissions::ORDERS_CREATE);
    }

    public function update(User $user, PurchaseOrder $order): bool
    {
        return in_array($order->status, ['draft', 'rfq'], true) && $this->operation($user, $order, PurchasingPermissions::ORDERS_UPDATE);
    }

    public function confirm(User $user, PurchaseOrder $order): bool
    {
        return in_array($order->status, ['draft', 'rfq'], true) && $this->operation($user, $order, PurchasingPermissions::ORDERS_CONFIRM);
    }

    public function cancel(User $user, PurchaseOrder $order): bool
    {
        return in_array($order->status, ['draft', 'rfq', 'confirmed'], true)
            && $this->operation($user, $order, PurchasingPermissions::ORDERS_CANCEL)
            && ! $this->hasCancellationHistory($order);
    }

    public function delete(User $user, PurchaseOrder $order): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function hasCancellationHistory(PurchaseOrder $order): bool
    {
        $facts = RequestAuthorizationFacts::readFacts('purchase-order-cancellation-history:'.$order->id);
        if ($facts !== null) {
            return (bool) $facts[0] || (bool) $facts[1] || (bool) $facts[2];
        }

        return RequestAuthorizationFacts::remember('purchase-order-has-cancellation-history:'.$order->id,
            fn (): bool => $order->receipts()->where('status', '!=', 'cancelled')->exists()
                || $order->receiptItems()->whereNotNull('stock_movement_id')->exists() || $order->vendorBills()->exists());
    }

    private function operation(User $user, PurchaseOrder $order, string $permission): bool
    {
        return $this->view($user, $order) && app(PurchasingAccess::class)->allows($user, $permission, (int) $order->location_id);
    }
}
