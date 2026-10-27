<?php

namespace App\Modules\Payments\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Payments\Services\PaymentsPermissions;

class CustomerPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return app(CustomerAccess::class)->allows($user, PaymentsPermissions::VIEW);
    }

    public function view(User $user, Payment $record): bool
    {
        return $record->isSettlementReceipt() && app(CustomerAccess::class)->allows($user, PaymentsPermissions::VIEW, (int) $record->location_id);
    }

    public function create(User $user): bool
    {
        return app(CustomerAccess::class)->allows($user, PaymentsPermissions::CREATE);
    }

    public function update(User $user, Payment $record): bool
    {
        return false;
    }

    public function delete(User $user, Payment $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
