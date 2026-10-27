<?php

namespace App\Modules\Payments\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Payments\Services\PaymentsPermissions;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return app(CustomerAccess::class)->allows($user, PaymentsPermissions::VIEW);
    }

    public function view(User $user, Customer $record): bool
    {
        return app(CustomerAccess::class)->allows($user, PaymentsPermissions::VIEW, (int) $record->location_id);
    }

    public function create(User $user): bool
    {
        return app(CustomerAccess::class)->allows($user, PaymentsPermissions::CUSTOMERS_MANAGE);
    }

    public function update(User $user, Customer $record): bool
    {
        return app(CustomerAccess::class)->allows($user, PaymentsPermissions::CUSTOMERS_MANAGE, (int) $record->location_id);
    }

    public function delete(User $user, Customer $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
