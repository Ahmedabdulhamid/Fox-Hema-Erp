<?php

namespace App\Modules\Sales\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesPermissions;

class SalesDeliveryPolicy
{
    public function viewAny(User $user): bool
    {
        return app(SalesAccess::class)->allows($user, SalesPermissions::DELIVERIES_VIEW);
    }

    public function view(User $user, SalesDelivery $delivery): bool
    {
        return app(SalesAccess::class)->allows($user, SalesPermissions::DELIVERIES_VIEW, (int) $delivery->location_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && app(SalesAccess::class)->allows($user, SalesPermissions::DELIVERIES_CREATE);
    }

    public function update(User $user, SalesDelivery $delivery): bool
    {
        return $delivery->status === 'draft' && $this->view($user, $delivery)
            && app(SalesAccess::class)->allows($user, SalesPermissions::DELIVERIES_UPDATE, (int) $delivery->location_id);
    }

    public function cancel(User $user, SalesDelivery $delivery): bool
    {
        return $delivery->status === 'draft' && $this->view($user, $delivery)
            && app(SalesAccess::class)->allows($user, SalesPermissions::DELIVERIES_CANCEL, (int) $delivery->location_id);
    }

    public function delete(User $user, SalesDelivery $delivery): bool
    {
        return false;
    }

    public function post(User $user, SalesDelivery $delivery): bool
    {
        return $delivery->status === 'draft' && $delivery->posted_at === null && $delivery->cancelled_at === null
            && $delivery->posted_by === null && $delivery->cancelled_by === null && $delivery->cancellation_payload_hash === null
            && $delivery->posting_payload_hash === null && $delivery->posting_evidence_hash === null && $delivery->posting_revision === null
            && in_array($delivery->salesOrder->status, ['confirmed', 'partially_fulfilled'], true)
            && $delivery->salesOrder->confirmed_at !== null && $delivery->salesOrder->confirmation_payload_hash !== null
            && $this->view($user, $delivery)
            && app(SalesAccess::class)->allows($user, SalesPermissions::DELIVERIES_POST, (int) $delivery->location_id)
            && app(InventoryAccess::class)->allows($user, InventoryPermissions::POST, (int) $delivery->location_id)
            && app(ModuleAccess::class)->isActive('inventory_costing');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
