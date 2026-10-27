<?php

namespace App\Modules\Treasury\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Treasury\Models\TreasuryOperation;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryWorkspace;

class TreasuryOperationPolicy
{
    public function viewAny(User $user): bool
    {
        return app(TreasuryWorkspace::class)->allows($user, TreasuryPermissions::VIEW, getPermissionsTeamId());
    }

    public function view(User $user, TreasuryOperation $operation): bool
    {
        return app(TreasuryWorkspace::class)->canOperate($user, $operation, TreasuryPermissions::VIEW);
    }

    public function create(User $user): bool
    {
        return app(TreasuryWorkspace::class)->allows($user, TreasuryPermissions::CREATE, getPermissionsTeamId());
    }

    public function post(User $user, TreasuryOperation $operation): bool
    {
        return $operation->status === 'draft' && $operation->cancelled_at === null
            && $operation->vendor_payment_id === null && $operation->payment_id === null
            && app(TreasuryWorkspace::class)->canOperate($user, $operation, TreasuryPermissions::POST)
            && ($operation->destination_account_id === null || app(TreasuryWorkspace::class)->canOperate($user, $operation, TreasuryPermissions::TRANSFER));
    }

    public function cancel(User $user, TreasuryOperation $operation): bool
    {
        return $operation->status === 'draft' && $operation->cancelled_at === null
            && $operation->type !== 'opening_balance'
            && $operation->vendor_payment_id === null && $operation->payment_id === null
            && app(TreasuryWorkspace::class)->canOperate($user, $operation, TreasuryPermissions::CREATE);
    }

    public function reverse(User $user, TreasuryOperation $operation): bool
    {
        return $operation->status === 'posted' && $operation->reversal_of_id === null
            && $operation->vendor_payment_id === null && $operation->payment_id === null && $operation->reversal === null
            && (! app(CustomerAccess::class)->ready() || ! PaymentRefund::where('treasury_operation_id', $operation->id)->exists())
            && app(TreasuryWorkspace::class)->canOperate($user, $operation, TreasuryPermissions::REVERSE)
            && app(TreasuryWorkspace::class)->canOperate($user, $operation, TreasuryPermissions::POST)
            && ($operation->destination_account_id === null || app(TreasuryWorkspace::class)->canOperate($user, $operation, TreasuryPermissions::TRANSFER));
    }

    public function update(User $user, TreasuryOperation $operation): bool
    {
        return false;
    }

    public function delete(User $user, TreasuryOperation $operation): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
