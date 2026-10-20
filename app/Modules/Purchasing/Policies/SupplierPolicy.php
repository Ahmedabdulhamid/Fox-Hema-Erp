<?php

namespace App\Modules\Purchasing\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Purchasing\Services\PurchasingPermissions;

class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return app(PurchasingAccess::class)->allows($user, PurchasingPermissions::VIEW);
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && app(PurchasingAccess::class)->allows($user, PurchasingPermissions::CREATE);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->viewAny($user) && app(PurchasingAccess::class)->allows($user, PurchasingPermissions::UPDATE);
    }

    public function deactivate(User $user, Supplier $supplier): bool
    {
        return $this->viewAny($user) && app(PurchasingAccess::class)->allows($user, PurchasingPermissions::DEACTIVATE);
    }

    public function activate(User $user, Supplier $supplier): bool
    {
        return $this->deactivate($user, $supplier);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Supplier $supplier): bool
    {
        return false;
    }
}
