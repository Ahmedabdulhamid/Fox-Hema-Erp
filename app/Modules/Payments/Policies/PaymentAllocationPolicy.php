<?php

namespace App\Modules\Payments\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Payments\Services\PaymentsPermissions;

class PaymentAllocationPolicy
{
    public function viewAny(User $user): bool
    {
        return app(CustomerAccess::class)->allows($user, PaymentsPermissions::VIEW);
    }

    public function view(User $user, PaymentAllocation $record): bool
    {
        return app(CustomerAccess::class)->allows($user, PaymentsPermissions::VIEW, (int) $record->payment->location_id);
    }

    public function create(User $user): bool
    {
        return app(CustomerAccess::class)->allows($user, PaymentsPermissions::ALLOCATE);
    }

    public function update(User $user, PaymentAllocation $record): bool
    {
        return false;
    }

    public function delete(User $user, PaymentAllocation $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
