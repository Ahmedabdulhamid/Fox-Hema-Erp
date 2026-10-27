<?php

namespace App\Modules\Payments\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Payments\Services\PaymentsPermissions;

class PaymentMethodPolicy
{
    public function viewAny(User $user): bool
    {
        return app(CustomerAccess::class)->allows($user, PaymentsPermissions::VIEW);
    }

    public function view(User $user, PaymentMethod $method): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return app(CustomerAccess::class)->allows($user, PaymentsPermissions::MANAGE_METHODS);
    }

    public function update(User $user, PaymentMethod $method): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, PaymentMethod $method): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
