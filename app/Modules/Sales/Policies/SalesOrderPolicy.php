<?php

namespace App\Modules\Sales\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesPermissions;

class SalesOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return app(SalesAccess::class)->allows($user, SalesPermissions::VIEW);
    }

    public function view(User $user, SalesOrder $order): bool
    {
        return app(SalesAccess::class)->allows($user, SalesPermissions::VIEW, (int) $order->location_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && app(SalesAccess::class)->allows($user, SalesPermissions::CREATE);
    }

    public function update(User $user, SalesOrder $order): bool
    {
        return $order->status === 'draft' && $order->fulfillment_status === 'unfulfilled'
            && $this->view($user, $order) && app(SalesAccess::class)->allows($user, SalesPermissions::UPDATE, (int) $order->location_id);
    }

    public function delete(User $user, SalesOrder $order): bool
    {
        return false;
    }

    public function confirm(User $user, SalesOrder $order): bool
    {
        return $order->status === 'draft' && $order->fulfillment_status === 'unfulfilled'
            && ! $order->hasDownstreamDocuments() && $this->view($user, $order)
            && app(SalesAccess::class)->allows($user, SalesPermissions::CONFIRM, (int) $order->location_id);
    }

    public function cancel(User $user, SalesOrder $order): bool
    {
        return in_array($order->status, ['draft', 'confirmed'], true) && $order->fulfillment_status === 'unfulfilled'
            && ! $order->hasDownstreamDocuments() && $this->view($user, $order)
            && ! $order->reservations()->where('quantity_consumed', '>', '0')->exists()
            && app(SalesAccess::class)->allows($user, SalesPermissions::CANCEL, (int) $order->location_id);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
