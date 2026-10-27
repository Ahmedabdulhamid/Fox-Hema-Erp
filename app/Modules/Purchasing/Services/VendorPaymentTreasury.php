<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryOperation;
use App\Modules\Treasury\Services\TreasuryAccess;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Validation\ValidationException;

class VendorPaymentTreasury
{
    private static int $recovering = 0;

    public function __construct(private readonly TreasuryAccess $access, private readonly PaymentMethods $methods) {}

    public static function isRecovering(): bool
    {
        return self::$recovering > 0;
    }

    public function validateSource(User $actor, int $accountId, int $methodId, ?string $reference, bool $posting = false): TreasuryAccount
    {
        $account = TreasuryAccount::whereKey($accountId)->lockForUpdate()->first();
        if ($account === null || ! $account->is_active || $account->currency !== 'EGP') {
            throw ValidationException::withMessages(['treasury_account_id' => 'Select an active EGP Treasury source account.']);
        }
        $method = $this->methods->resolve($methodId, $reference);
        $supported = ($method->type === 'cash' && in_array($account->type, ['branch_cashbox', 'central_cashbox'], true))
            || ($method->type === 'bank_transfer' && $account->type === 'bank');
        if (! $supported) {
            throw ValidationException::withMessages(['treasury_account_id' => 'Use cash with a branch/central cashbox or bank transfer with a bank account. This settlement mechanism is unsupported.']);
        }
        $this->access->operation($actor, TreasuryPermissions::CREATE, $account->location_id);
        if ($posting) {
            $this->access->operation($actor, TreasuryPermissions::POST, $account->location_id);
        }

        return $account;
    }

    public function assertLinked(VendorPayment $payment, bool $allowRecovery = false): TreasuryOperation
    {
        $operation = $payment->treasuryOperation()->lockForUpdate()->first();
        if ($operation === null || $operation->status !== 'posted' || $operation->type !== 'payment_disbursement'
            || (int) $operation->account_id !== (int) $payment->treasury_account_id || $operation->amount !== $payment->amount
            || $operation->currency !== $payment->currency || $operation->source_reference !== $payment->uuid
            || (! $allowRecovery && TreasuryOperation::where('reversal_of_id', $operation->id)->lockForUpdate()->first() !== null)) {
            throw ValidationException::withMessages(['payment' => 'Vendor payment Treasury evidence is inconsistent; explicit reconciliation is required.']);
        }

        return $operation;
    }

    public function authorizeRecovery(User $actor, VendorPayment $payment): void
    {
        $this->assertLinked($payment, allowRecovery: true);
        $account = TreasuryAccount::whereKey($payment->treasury_account_id)->lockForUpdate()->firstOrFail();
        if (! $account->is_active || $account->currency !== 'EGP') {
            throw ValidationException::withMessages(['treasury_account_id' => 'Recovery requires the original active EGP account.']);
        }
        $this->access->operation($actor, TreasuryPermissions::REVERSE, $account->location_id);
        $this->access->operation($actor, TreasuryPermissions::POST, $account->location_id);
    }

    public function recover(User $actor, VendorPayment $payment, string $key, string $reason, string $reference): TreasuryOperation
    {
        abort_unless(VendorPaymentService::isWriting((int) $payment->id), 403);
        $original = $this->assertLinked($payment);
        self::$recovering++;
        try {
            return app(TreasuryService::class)->reverse($actor, $original, $key, $reason.' | Verified recovery: '.$reference);
        } finally {
            self::$recovering--;
        }
    }

    public function movement(User $actor, VendorPayment $payment): ?TreasuryOperation
    {
        $payment = VendorPayment::findOrFail($payment->id);
        app(VendorPaymentAccess::class)->authorize($actor, PurchasingPermissions::PAYMENTS_VIEW, $payment->location_id);
        if ($payment->treasury_account_id === null) {
            return null;
        }
        $account = TreasuryAccount::findOrFail($payment->treasury_account_id);
        $this->access->authorize($actor, TreasuryPermissions::VIEW, $account->location_id);

        return $payment->treasuryOperation()->first();
    }
}
