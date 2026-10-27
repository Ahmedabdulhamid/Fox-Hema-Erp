<?php

namespace App\Modules\Payments\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Models\CustomerInvoiceAccount;
use App\Modules\Payments\Models\Payment;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CustomerPayments
{
    private static int $posting = 0;

    public static function isPosting(): bool
    {
        return self::$posting > 0;
    }

    public function payInvoice(User $actor, SalesInvoice $invoice, array $data, string $key): Payment
    {
        return DB::transaction(function () use ($actor, $invoice, $data, $key): Payment {
            app(CustomerAccess::class)->lock();
            $invoice = SalesInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            app(SalesAccess::class)->authorize($actor, SalesPermissions::INVOICES_VIEW, (int) $invoice->location_id);
            if (array_key_exists('customer_id', $data)) {
                app(CustomerAccess::class)->invalid('customer_id', 'Unsupported customer payment fields.');
            }
            $link = CustomerInvoiceAccount::where('sales_invoice_id', $invoice->id)->lockForUpdate()->first();
            $payment = $this->createDraft($actor, $data + ['customer_id' => $link?->customer_id], $key, $invoice);
            $payment = $this->post($actor, $payment);
            app(CustomerAllocations::class)->allocate($actor, $payment, $invoice, $payment->amount, $key);

            return $payment;
        }, 3);
    }

    public function createDraft(User $actor, array $data, string $key, ?SalesInvoice $invoice = null): Payment
    {
        return DB::transaction(function () use ($actor, $data, $key, $invoice): Payment {
            $access = app(CustomerAccess::class);
            $access->lock();
            $access->key($key);
            $allowed = ['customer_id', 'payment_method_id', 'treasury_account_id', 'amount', 'payment_date', 'external_reference', 'notes'];
            if (array_diff(array_keys($data), $allowed) !== []) {
                $access->invalid('payment', 'Unsupported customer payment fields.');
            }
            $data = array_replace(['customer_id' => null, 'external_reference' => null, 'notes' => null], $data);
            Validator::make($data, ['customer_id' => [$invoice === null ? 'required' : 'nullable', 'integer'], 'payment_method_id' => ['required', 'integer'], 'treasury_account_id' => ['required', 'integer'],
                'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'external_reference' => ['nullable', 'string', 'max:191'], 'notes' => ['nullable', 'string', 'max:16000']], [], __('payments.validation_attributes'))->validate();
            $customer = ($data['customer_id'] ?? null) === null ? null : Customer::whereKey($data['customer_id'])->lockForUpdate()->firstOrFail();
            if ($invoice !== null) {
                $invoice = SalesInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                app(SalesAccess::class)->authorize($actor, SalesPermissions::INVOICES_VIEW, (int) $invoice->location_id);
                if ($invoice->status !== 'posted' || $invoice->cancelled_at !== null || $invoice->currency !== 'EGP'
                    || ($customer !== null && (int) $customer->location_id !== (int) $invoice->location_id)) {
                    $access->invalid('invoice', 'Select a posted EGP invoice and an active customer in the same branch.');
                }
            }
            $location = (int) ($invoice?->location_id ?? $customer->location_id);
            $access->operation($actor, PaymentsPermissions::CREATE, $location);
            foreach (['customer_id', 'payment_method_id', 'treasury_account_id'] as $field) {
                $data[$field] = $data[$field] === null ? null : (int) $data[$field];
            }
            $data['amount'] = app(PaymentMoney::class)->normalize($data['amount'] ?? null, 'EGP');
            $payload = $data + ['location_id' => $location, 'direction' => 'in', 'currency' => 'EGP', 'source_document_type' => $invoice === null ? null : 'sales_invoice', 'source_document_reference' => $invoice?->uuid];
            $hash = app(PaymentFingerprint::class)->make('payments', 'create', $payload + ['actor_id' => $actor->id]);
            $existing = Payment::where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                $access->match($existing->payload_fingerprint, $hash);

                return $existing;
            }
            if ($customer !== null && ! $customer->is_active) {
                $access->invalid('customer_id', 'The customer is inactive.');
            }
            app(CustomerTreasury::class)->account($actor, $data['treasury_account_id'], $location, $data['payment_method_id'], $data['external_reference']);

            return CustomerAccess::write(fn () => Payment::create($payload + ['status' => 'draft', 'origin_module' => 'payments', 'created_by' => $actor->id, 'idempotency_key' => $key, 'payload_fingerprint' => $hash]));
        }, 3);
    }

    public function post(User $actor, Payment $payment): Payment
    {
        return DB::transaction(function () use ($actor, $payment): Payment {
            $access = app(CustomerAccess::class);
            $access->lock();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $access->operation($actor, PaymentsPermissions::POST, (int) $payment->location_id);
            if (! $payment->isSettlementReceipt() || $payment->treasury_account_id === null || $payment->direction !== 'in' || $payment->currency !== 'EGP') {
                $access->invalid('payment', 'Select a customer receipt.');
            }
            $customer = $payment->customer_id === null ? null : Customer::whereKey($payment->customer_id)->lockForUpdate()->firstOrFail();
            if ($customer === null) {
                $invoice = SalesInvoice::where('uuid', $payment->source_document_reference)->lockForUpdate()->first();
                if ($payment->source_document_type !== 'sales_invoice' || $invoice === null || $invoice->status !== 'posted'
                    || $invoice->cancelled_at !== null || (int) $invoice->location_id !== (int) $payment->location_id) {
                    $access->invalid('payment', 'Select a posted EGP invoice and an active customer in the same branch.');
                }
                app(SalesAccess::class)->authorize($actor, SalesPermissions::INVOICES_VIEW, (int) $invoice->location_id);
            }
            if ($customer !== null && (int) $customer->location_id !== (int) $payment->location_id) {
                $access->invalid('customer', 'Customer branch ownership changed.');
            }
            if ($payment->status === 'posted') {
                app(CustomerTreasury::class)->receipt($payment);

                return $payment;
            }
            if ($customer !== null && ! $customer->is_active) {
                $access->invalid('customer', 'The customer is inactive.');
            }
            $account = app(CustomerTreasury::class)->account($actor, (int) $payment->treasury_account_id, (int) $payment->location_id, (int) $payment->payment_method_id, $payment->external_reference, true);
            self::$posting++;
            try {
                $payment = app(PaymentService::class)->post($actor, $payment);
                $treasury = app(TreasuryService::class);
                $movement = $treasury->createPaymentDraft($actor, $account, $payment, $payment->idempotency_key, $payment->payment_date->format('Y-m-d'), 'Customer receipt '.$payment->payment_number);
                $treasury->post($actor, $movement);
            } finally {
                self::$posting--;
            }

            return $payment->refresh();
        }, 3);
    }
}
