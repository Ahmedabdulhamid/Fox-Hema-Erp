<?php

namespace App\Modules\Payments\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\CustomerInvoiceAccount;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesPermissions;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CustomerAllocations
{
    public function allocate(User $actor, Payment $payment, SalesInvoice $invoice, mixed $amount, string $key): PaymentAllocation
    {
        return DB::transaction(function () use ($actor, $payment, $invoice, $amount, $key): PaymentAllocation {
            $access = app(CustomerAccess::class);
            $access->lock();
            $access->key($key);
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $invoice = SalesInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $access->operation($actor, PaymentsPermissions::ALLOCATE, (int) $payment->location_id);
            app(SalesAccess::class)->authorize($actor, SalesPermissions::INVOICES_VIEW, (int) $invoice->location_id);
            $amount = app(PaymentMoney::class)->normalize($amount, 'EGP');
            $hash = app(PaymentFingerprint::class)->make('payments', 'allocate', ['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => $amount, 'actor_id' => $actor->id]);
            $existing = PaymentAllocation::where('operation_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                $access->match($existing->payload_fingerprint, $hash);

                return $existing;
            }
            $link = CustomerInvoiceAccount::where('sales_invoice_id', $invoice->id)->lockForUpdate()->first();
            $matchesCustomer = $payment->customer_id !== null && $link !== null && (int) $link->customer_id === (int) $payment->customer_id;
            $matchesWalkIn = $payment->customer_id === null && $link === null && $payment->source_document_type === 'sales_invoice'
                && $payment->source_document_reference === $invoice->uuid && $payment->treasury_account_id !== null;
            if ($payment->status !== 'posted' || (! $matchesCustomer && ! $matchesWalkIn) || (int) $payment->location_id !== (int) $invoice->location_id ||
            $invoice->status !== 'posted' || $invoice->cancelled_at !== null || $invoice->currency !== 'EGP') {
                $access->invalid('invoice', 'Allocate only posted receipts to the same customer and branch posted invoice.');
            }
            app(CustomerTreasury::class)->receipt($payment);
            $financials = app(CustomerFinancials::class);
            if (BigDecimal::of($amount)->isGreaterThan($financials->available($payment, $actor, true)) || BigDecimal::of($amount)->isGreaterThan($financials->invoice($actor, $invoice, true)['outstanding'])) {
                $access->invalid('amount', 'Allocation exceeds the available receipt or invoice outstanding amount.');
            }

            return CustomerAccess::write(fn () => PaymentAllocation::create(['payment_id' => $payment->id, 'sales_invoice_id' => $invoice->id, 'amount' => $amount, 'operation_key' => $key, 'payload_fingerprint' => $hash, 'allocated_by' => $actor->id, 'allocated_at' => now()]));
        }, 3);
    }

    public function release(User $actor, PaymentAllocation $allocation, string $key, string $reason): PaymentAllocation
    {
        return DB::transaction(function () use ($actor, $allocation, $key, $reason): PaymentAllocation {
            $access = app(CustomerAccess::class);
            $access->lock();
            $access->key($key);
            $allocation = PaymentAllocation::whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($allocation->payment_id)->lockForUpdate()->firstOrFail();
            $access->operation($actor, PaymentsPermissions::ALLOCATIONS_RELEASE, (int) $payment->location_id);
            app(SalesAccess::class)->authorize($actor, SalesPermissions::INVOICES_VIEW, (int) $allocation->invoice->location_id);
            Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:16000']], [], __('payments.validation_attributes'))->validate();
            $hash = app(PaymentFingerprint::class)->make('payments', 'release', ['allocation_id' => $allocation->id, 'reason' => $reason, 'actor_id' => $actor->id]);
            if ($allocation->released_at !== null) {
                if ($allocation->release_key !== $key) {
                    $access->invalid('allocation', 'This allocation was already released.');
                }
                $access->match($allocation->release_fingerprint, $hash);

                return $allocation;
            }
            if ($allocation->operation_key === null || (! $payment->isSettlementReceipt())
                || ($payment->customer_id === null && ($payment->source_document_type !== 'sales_invoice' || $payment->source_document_reference !== $allocation->invoice->uuid))) {
                $access->invalid('allocation', 'Legacy allocations require reconciliation.');
            }
            app(CustomerTreasury::class)->receipt($payment);
            CustomerAccess::write(fn () => $allocation->fill(['released_at' => now(), 'released_by' => $actor->id, 'release_key' => $key, 'release_fingerprint' => $hash, 'release_reason' => $reason])->save());

            return $allocation->refresh();
        }, 3);
    }
}
