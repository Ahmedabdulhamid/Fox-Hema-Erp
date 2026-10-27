<?php

namespace App\Modules\Purchasing\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Purchasing\Services\VendorPaymentAccess;
use App\Modules\Purchasing\Services\VendorPaymentTreasury;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VendorPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, PurchasingPermissions::PAYMENTS_VIEW);
    }

    public function view(User $user, VendorPayment $payment): bool
    {
        return $this->allows($user, PurchasingPermissions::PAYMENTS_VIEW, $payment->location_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $this->allows($user, PurchasingPermissions::PAYMENTS_CREATE);
    }

    public function post(User $user, VendorPayment $payment): bool
    {
        return $payment->status === 'draft' && $payment->treasury_account_id !== null
            && $this->allows($user, PurchasingPermissions::PAYMENTS_POST, $payment->location_id);
    }

    public function recover(User $user, VendorPayment $payment): bool
    {
        if ($payment->status !== 'posted' || $payment->treasury_account_id === null
            || ! $this->allows($user, PurchasingPermissions::PAYMENTS_REVERSE, $payment->location_id)) {
            return false;
        }
        try {
            app(VendorPaymentTreasury::class)->authorizeRecovery($user, $payment);

            return true;
        } catch (AuthorizationException|HttpException|ValidationException) {
            return false;
        }
    }

    public function update(User $user, VendorPayment $payment): bool
    {
        return false;
    }

    public function delete(User $user, VendorPayment $payment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function allows(User $user, string $permission, ?int $location = null): bool
    {
        try {
            app(VendorPaymentAccess::class)->authorize($user, PurchasingPermissions::PAYMENTS_VIEW, $location);
            app(VendorPaymentAccess::class)->authorize($user, $permission, $location);

            return true;
        } catch (AuthorizationException|HttpException) {
            return false;
        }
    }
}
