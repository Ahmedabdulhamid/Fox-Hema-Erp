<?php

namespace App\Modules\Sales\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesPermissions;

class SalesReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return app(SalesAccess::class)->allows($user, SalesPermissions::RETURNS_VIEW);
    }

    public function view(User $user, SalesReturn $return): bool
    {
        return app(SalesAccess::class)->allows($user, SalesPermissions::RETURNS_VIEW, (int) $return->location_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && app(SalesAccess::class)->allows($user, SalesPermissions::RETURNS_CREATE);
    }

    public function update(User $user, SalesReturn $return): bool
    {
        return $this->clean($return) && $return->status === 'draft' && $this->view($user, $return)
            && app(SalesAccess::class)->allows($user, SalesPermissions::RETURNS_UPDATE, (int) $return->location_id);
    }

    public function approve(User $user, SalesReturn $return): bool
    {
        return (int) $user->id !== (int) $return->created_by && $this->clean($return) && $return->status === 'draft'
            && $this->view($user, $return)
            && app(SalesAccess::class)->allows($user, SalesPermissions::RETURNS_APPROVE, (int) $return->location_id);
    }

    public function cancel(User $user, SalesReturn $return): bool
    {
        return $this->clean($return) && in_array($return->status, ['draft', 'approved'], true)
            && $this->view($user, $return)
            && app(SalesAccess::class)->allows($user, SalesPermissions::RETURNS_CANCEL, (int) $return->location_id);
    }

    public function post(User $user, SalesReturn $return): bool
    {
        return $return->status === 'approved' && $this->clean($return) && $this->view($user, $return)
            && app(SalesAccess::class)->allows($user, SalesPermissions::RETURNS_POST, (int) $return->location_id)
            && app(InventoryAccess::class)->allows($user, InventoryPermissions::POST, (int) $return->location_id)
            && app(ModuleAccess::class)->isActive('inventory_costing');
    }

    public function delete(User $user, SalesReturn $return): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function clean(SalesReturn $return): bool
    {
        return $return->submission_payload_hash !== null && $return->posted_at === null && $return->posted_by === null
            && $return->cancelled_at === null && $return->cancelled_by === null
            && ! $return->creditNotes()->exists() && ! $return->items()->whereHas('creditNoteItems')->exists();
    }
}
