<?php

namespace App\Modules\Payments\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryOperation;
use App\Modules\Treasury\Services\TreasuryAccess;
use App\Modules\Treasury\Services\TreasuryPermissions;
use Illuminate\Database\Eloquent\Builder;

class CustomerTreasury
{
    private const METHOD_TYPES = ['cash', 'card', 'bank_transfer'];

    public function methods(): Builder
    {
        return PaymentMethod::query()->where('is_active', true)->whereIn('type', self::METHOD_TYPES)->orderBy('sort_order')->orderBy('id');
    }

    public function account(User $actor, int $id, int $location, int $methodId, ?string $reference, bool $posting = false, bool $historicalMethod = false): TreasuryAccount
    {
        $method = app(PaymentMethods::class)->resolve($methodId, $reference, $historicalMethod);
        $account = TreasuryAccount::whereKey($id)->lockForUpdate()->firstOrFail();
        app(TreasuryAccess::class)->operation($actor, $posting ? TreasuryPermissions::POST : TreasuryPermissions::CREATE, $account->location_id);
        if (! $account->is_active || $account->currency !== 'EGP' || (int) $account->location_id !== $location ||
        ! (($method->type === 'cash' && $account->type !== 'bank') || (in_array($method->type, ['bank_transfer', 'card'], true) && $account->type === 'bank'))) {
            app(CustomerAccess::class)->invalid('treasury_account_id', 'Choose an active account in the customer branch matching the cash or bank method.');
        }

        return $account;
    }

    public function receipt(Payment $payment): TreasuryOperation
    {
        $operation = TreasuryOperation::where('payment_id', $payment->id)->lockForUpdate()->first();
        if ($operation === null || $operation->status !== 'posted' || $operation->type !== 'payment_receipt' ||
        (int) $operation->account_id !== (int) $payment->treasury_account_id || $operation->amount !== $payment->amount ||
        $operation->currency !== $payment->currency || $operation->reversal()->exists()) {
            app(CustomerAccess::class)->invalid('payment', 'The customer receipt requires intact posted Treasury evidence.');
        }

        return $operation;
    }
}
