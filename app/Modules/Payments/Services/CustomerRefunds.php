<?php

namespace App\Modules\Payments\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\CustomerInvoiceAccount;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesCreditNoteService;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Treasury\Models\TreasuryOperation;
use App\Modules\Treasury\Services\TreasuryService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CustomerRefunds
{
    private static int $recovering = 0;

    public static function isRecovering(): bool
    {
        return self::$recovering > 0;
    }

    public function refundCreditNote(User $actor, SalesCreditNote $credit, array $data, string $key): PaymentRefund
    {
        return DB::transaction(function () use ($actor, $credit, $data, $key): PaymentRefund {
            $access = app(CustomerAccess::class);
            $access->lock();
            $access->key($key);
            if (array_diff(array_keys($data), ['payment_id', 'amount', 'refund_date', 'external_reference', 'reason']) !== []) {
                $access->invalid('refund', 'Unsupported refund fields.');
            }
            Validator::make($data, ['payment_id' => ['required', 'integer']], [], __('payments.validation_attributes'))->validate();
            $payment = Payment::whereKey($data['payment_id'])->lockForUpdate()->firstOrFail();
            $access->operation($actor, PaymentsPermissions::REFUNDS_CREATE, (int) $payment->location_id);
            $access->operation($actor, PaymentsPermissions::REFUNDS_POST, (int) $payment->location_id);
            $credit = $this->credit($actor, $payment, (int) $credit->id);
            app(SalesCreditNoteService::class)->verifyPosted($credit);
            $data['sales_credit_note_id'] = $credit->id;
            $data['amount'] = app(PaymentMoney::class)->normalize($data['amount'] ?? null, 'EGP');
            if (! PaymentRefund::where('idempotency_key', $key)->lockForUpdate()->exists()) {
                $financials = app(CustomerFinancials::class);
                $needed = BigDecimal::of($data['amount'])->minus($financials->available($payment, $actor, true));
                if ($needed->isPositive()) {
                    $invoice = $credit->salesInvoice()->lockForUpdate()->firstOrFail();
                    $excess = $financials->invoice($actor, $invoice, true)['excess_allocation'];
                    if ($needed->isGreaterThan($excess)) {
                        $access->invalid('amount', 'payments.refund_exceeds_balance');
                    }
                    $allocations = app(CustomerAllocations::class);
                    foreach (PaymentAllocation::where('payment_id', $payment->id)->where('sales_invoice_id', $invoice->id)->whereNull('released_at')->orderBy('id')->lockForUpdate()->get() as $allocation) {
                        $allocations->release($actor, $allocation, (string) Str::uuid7(), __('payments.credit_refund_adjustment').' '.$credit->credit_note_number);
                        $remaining = BigDecimal::min($allocation->amount, $financials->invoice($actor, $invoice, true)['outstanding']);
                        if ($remaining->isPositive()) {
                            $allocations->allocate($actor, $payment, $invoice, (string) $remaining, (string) Str::uuid7());
                        }
                        if (BigDecimal::of($data['amount'])->isLessThanOrEqualTo($financials->available($payment, $actor, true))) {
                            break;
                        }
                    }
                }
            }

            return $this->post($actor, $this->createDraft($actor, $data, $key));
        }, 3);
    }

    /** @return array{refunded: string, remaining: string} */
    public function creditBalance(User $actor, SalesCreditNote $credit): array
    {
        app(CustomerAccess::class)->operation($actor, PaymentsPermissions::VIEW, (int) $credit->location_id);
        app(SalesAccess::class)->authorize($actor, SalesPermissions::CREDITS_VIEW, (int) $credit->location_id);
        $refunded = (string) PaymentRefund::where('sales_credit_note_id', $credit->id)->where('status', 'posted')->whereNull('recovered_at')->sum('amount');

        return ['refunded' => $refunded, 'remaining' => (string) BigDecimal::max('0', BigDecimal::of($credit->total_amount)->minus($refunded))];
    }

    public function refundableAmount(User $actor, SalesCreditNote $credit, Payment $payment): string
    {
        $remaining = $this->creditBalance($actor, $credit)['remaining'];
        $available = app(CustomerFinancials::class)->available($payment, $actor);
        $excess = app(CustomerFinancials::class)->invoice($actor, $credit->salesInvoice)['excess_allocation'];
        $allocated = (string) PaymentAllocation::where('payment_id', $payment->id)->where('sales_invoice_id', $credit->sales_invoice_id)->whereNull('released_at')->sum('amount');

        return (string) BigDecimal::max('0', BigDecimal::min($remaining, BigDecimal::of($available)->plus(BigDecimal::min($allocated, $excess))));
    }

    public function recover(User $actor, PaymentRefund $refund, string $key, string $reason, string $reference): PaymentRefund
    {
        return DB::transaction(function () use ($actor, $refund, $key, $reason, $reference): PaymentRefund {
            $access = app(CustomerAccess::class);
            $access->lock();
            $access->key($key);
            $refund = PaymentRefund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();
            $access->operation($actor, PaymentsPermissions::REFUNDS_RECOVER, (int) $payment->location_id);
            Validator::make(['reason' => $reason, 'reference' => $reference], ['reason' => ['required', 'string', 'max:16000'], 'reference' => ['required', 'string', 'max:191']], [], __('payments.validation_attributes'))->validate();
            $hash = app(PaymentFingerprint::class)->make('payments', 'refund_recovery', ['refund_id' => $refund->id, 'reason' => $reason, 'reference' => $reference, 'actor_id' => $actor->id]);
            if ($refund->recovered_at !== null) {
                if ($refund->recovery_key !== $key) {
                    $access->invalid('refund', 'This refund was already recovered.');
                }
                $access->match($refund->recovery_fingerprint, $hash);

                return $refund;
            }
            if ($refund->status !== 'posted' || $refund->treasury_operation_id === null || ! $payment->isSettlementReceipt()) {
                $access->invalid('refund', 'Recover only a posted customer refund with Treasury evidence.');
            }
            app(CustomerTreasury::class)->receipt($payment);
            app(CustomerTreasury::class)->account($actor, (int) $refund->treasury_account_id, (int) $payment->location_id, (int) $refund->payment_method_id, $reference, true, true);
            $movement = TreasuryOperation::whereKey($refund->treasury_operation_id)->lockForUpdate()->firstOrFail();
            if ($movement->status !== 'posted' || $movement->amount !== $refund->amount || (int) $movement->account_id !== (int) $refund->treasury_account_id ||
                $movement->source_reference !== 'customer-refund:'.$refund->uuid) {
                $access->invalid('refund', 'Refund Treasury evidence does not match.');
            }
            self::$recovering++;
            try {
                $reversal = app(TreasuryService::class)->reverse($actor, $movement, $key, $reason.' | '.$reference);
                CustomerAccess::write(fn () => $refund->fill(['recovered_at' => now(), 'recovered_by' => $actor->id, 'recovery_key' => $key, 'recovery_fingerprint' => $hash,
                    'recovery_reason' => $reason, 'recovery_reference' => $reference, 'recovery_operation_id' => $reversal->id])->save());
            } finally {
                self::$recovering--;
            }

            return $refund->refresh();
        }, 3);
    }

    public function createDraft(User $actor, array $data, string $key): PaymentRefund
    {
        return DB::transaction(function () use ($actor, $data, $key): PaymentRefund {
            $access = app(CustomerAccess::class);
            $access->lock();
            $access->key($key);
            if (array_diff(array_keys($data), ['payment_id', 'sales_credit_note_id', 'amount', 'refund_date', 'external_reference', 'reason']) !== []) {
                $access->invalid('refund', 'Unsupported refund fields.');
            }
            $data = array_replace(['sales_credit_note_id' => null, 'external_reference' => null], $data);
            Validator::make($data, ['payment_id' => ['required', 'integer'], 'sales_credit_note_id' => ['nullable', 'integer'], 'refund_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'external_reference' => ['nullable', 'string', 'max:191'], 'reason' => ['required', 'string', 'max:16000']], [], __('payments.validation_attributes'))->validate();
            $payment = Payment::whereKey($data['payment_id'])->lockForUpdate()->firstOrFail();
            $access->operation($actor, PaymentsPermissions::REFUNDS_CREATE, (int) $payment->location_id);
            $data['amount'] = app(PaymentMoney::class)->normalize($data['amount'] ?? null, 'EGP');
            $data['payment_id'] = (int) $payment->id;
            $data['sales_credit_note_id'] = $data['sales_credit_note_id'] === null ? null : (int) $data['sales_credit_note_id'];
            $hash = app(PaymentFingerprint::class)->make('payments', 'refund', $data + ['actor_id' => $actor->id]);
            $existing = PaymentRefund::where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                $access->match($existing->payload_fingerprint, $hash);

                return $existing;
            }
            $this->validateAvailable($actor, $payment, $data['amount'], $data['sales_credit_note_id']);
            app(CustomerTreasury::class)->account($actor, (int) $payment->treasury_account_id, (int) $payment->location_id, (int) $payment->payment_method_id, $data['external_reference'], historicalMethod: true);
            if ($data['refund_date'] < $payment->payment_date->format('Y-m-d')) {
                $access->invalid('refund_date', 'Refund date cannot precede the receipt.');
            }

            return CustomerAccess::write(fn () => PaymentRefund::create($data + ['payment_method_id' => $payment->payment_method_id, 'treasury_account_id' => $payment->treasury_account_id,
                'status' => 'draft', 'created_by' => $actor->id, 'idempotency_key' => $key, 'payload_fingerprint' => $hash]));
        }, 3);
    }

    public function post(User $actor, PaymentRefund $refund): PaymentRefund
    {
        return DB::transaction(function () use ($actor, $refund): PaymentRefund {
            $access = app(CustomerAccess::class);
            $access->lock();
            $refund = PaymentRefund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();
            $access->operation($actor, PaymentsPermissions::REFUNDS_POST, (int) $payment->location_id);
            if ($refund->status === 'posted') {
                $movement = TreasuryOperation::whereKey($refund->treasury_operation_id)->first();
                if ($movement === null || $movement->status !== 'posted' || $movement->amount !== $refund->amount || ($refund->recovered_at === null && $movement->reversal()->exists())) {
                    $access->invalid('refund', 'Refund Treasury evidence is invalid.');
                }

                return $refund;
            }
            if ($refund->status !== 'draft' || $refund->payload_fingerprint === null) {
                $access->invalid('refund', 'Only controlled refund drafts can be posted.');
            }
            $data = $refund->only(['payment_id', 'sales_credit_note_id', 'amount', 'external_reference', 'reason']);
            $data['refund_date'] = $refund->refund_date->format('Y-m-d');
            $access->match($refund->payload_fingerprint, app(PaymentFingerprint::class)->make('payments', 'refund', $data + ['actor_id' => $refund->created_by]));
            $this->validateAvailable($actor, $payment, $refund->amount, $refund->sales_credit_note_id);
            if ((int) $refund->treasury_account_id !== (int) $payment->treasury_account_id || (int) $refund->payment_method_id !== (int) $payment->payment_method_id) {
                $access->invalid('refund', 'Refunds must use the original receipt account and method.');
            }
            $account = app(CustomerTreasury::class)->account($actor, (int) $refund->treasury_account_id, (int) $payment->location_id, (int) $refund->payment_method_id, $refund->external_reference, true, true);
            $treasury = app(TreasuryService::class);
            $movement = $treasury->createDraft($actor, ['type' => $account->type === 'bank' ? 'bank_withdrawal' : 'cash_withdrawal', 'account_id' => $account->id,
                'amount' => $refund->amount, 'currency' => 'EGP', 'effective_date' => $refund->refund_date->format('Y-m-d'), 'source_type' => 'external_other',
                'source_reference' => 'customer-refund:'.$refund->uuid, 'reason' => 'Customer refund '.$refund->refund_number.': '.$refund->reason], $refund->idempotency_key);
            $treasury->post($actor, $movement);
            CustomerAccess::write(fn () => $refund->fill(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $actor->id, 'refunded_at' => now(), 'treasury_operation_id' => $movement->id])->save());

            return $refund->refresh();
        }, 3);
    }

    private function validateAvailable(User $actor, Payment $payment, string $amount, ?int $creditId): void
    {
        $access = app(CustomerAccess::class);
        if (! $payment->isSettlementReceipt() || ($payment->customer_id === null && $creditId === null) || $payment->status !== 'posted' || $payment->currency !== 'EGP') {
            $access->invalid('payment', 'Refund a posted customer receipt only.');
        }
        app(CustomerTreasury::class)->receipt($payment);
        if (BigDecimal::of($amount)->isGreaterThan(app(CustomerFinancials::class)->available($payment, $actor, true))) {
            $access->invalid('amount', 'Refund exceeds the unallocated receipt. Release excess invoice allocations first.');
        }
        if ($creditId === null) {
            return;
        }
        $credit = $this->credit($actor, $payment, $creditId);
        $refunded = BigDecimal::of('0.0000');
        foreach (PaymentRefund::where('sales_credit_note_id', $creditId)->where('status', 'posted')->whereNull('recovered_at')->orderBy('id')->lockForUpdate()->get(['amount']) as $postedRefund) {
            $refunded = $refunded->plus($postedRefund->amount);
        }
        if (BigDecimal::of($amount)->plus($refunded)->isGreaterThan($credit->total_amount)) {
            $access->invalid('amount', 'Refund exceeds the credit note amount.');
        }
    }

    private function credit(User $actor, Payment $payment, int $creditId): SalesCreditNote
    {
        $access = app(CustomerAccess::class);
        $credit = SalesCreditNote::whereKey($creditId)->lockForUpdate()->firstOrFail();
        app(SalesAccess::class)->authorize($actor, SalesPermissions::CREDITS_VIEW, (int) $credit->location_id);
        $link = CustomerInvoiceAccount::where('sales_invoice_id', $credit->sales_invoice_id)->lockForUpdate()->first();
        if ($credit->status !== 'posted' || $credit->cancelled_at !== null || $credit->salesInvoice->currency !== 'EGP' || (! (($payment->customer_id !== null && $link !== null && (int) $link->customer_id === (int) $payment->customer_id)
            || ($payment->customer_id === null && $link === null && $payment->source_document_type === 'sales_invoice' && $payment->source_document_reference === $credit->salesInvoice->uuid))) || (int) $credit->location_id !== (int) $payment->location_id ||
        ! PaymentAllocation::where('payment_id', $payment->id)->where('sales_invoice_id', $credit->sales_invoice_id)->exists()) {
            $access->invalid('sales_credit_note_id', 'Credit note must belong to the same customer invoice and original payment.');
        }

        return $credit;
    }
}
