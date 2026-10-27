<?php

namespace App\Modules\Payments\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Models\CustomerInvoiceAccount;
use App\Modules\Payments\Models\Payment;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CustomerAccounts
{
    public function __construct(private readonly CustomerAccess $access) {}

    public function save(User $actor, array $data, ?Customer $customer = null): Customer
    {
        return DB::transaction(function () use ($actor, $data, $customer): Customer {
            $this->access->lock();
            $customer = $customer === null ? null : Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $allowed = $customer === null ? ['location_id', 'name', 'phone', 'email', 'is_active'] : ['name', 'phone', 'email', 'is_active'];
            if (array_diff(array_keys($data), $allowed) !== []) {
                $this->access->invalid('customer', 'Customer codes and branch ownership are immutable.');
            }
            $values = array_replace($customer?->only(['location_id', 'name', 'phone', 'email', 'is_active']) ?? ['phone' => null, 'email' => null, 'is_active' => true], $data);
            Validator::make($values, ['location_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:191'],
                'phone' => ['nullable', 'string', 'max:60'], 'email' => ['nullable', 'email', 'max:191'], 'is_active' => ['required', 'boolean']], [], __('payments.validation_attributes'))->validate();
            $this->access->operation($actor, PaymentsPermissions::CUSTOMERS_MANAGE, (int) $values['location_id']);
            abort_unless(Location::whereKey($values['location_id'])->where('type', 'branch')->where('is_active', true)->exists(), 403);
            $result = CustomerAccess::write(function () use ($actor, $customer, $values): Customer {
                if ($customer === null) {
                    return Customer::create($values + ['created_by' => $actor->id]);
                }
                $customer->fill($values)->save();

                return $customer;
            });
            app(AuditLogger::class)->record('customer_account_saved', metadata: ['customer_id' => $result->id, 'location_id' => $result->location_id], actor: $actor);

            return $result->refresh();
        }, 3);
    }

    /** Explicit association only; never infer ownership from a name or telephone number. */
    public function linkInvoice(User $actor, Customer $customer, SalesInvoice $invoice, array $terms, string $key): CustomerInvoiceAccount
    {
        return DB::transaction(function () use ($actor, $customer, $invoice, $terms, $key): CustomerInvoiceAccount {
            $this->access->lock();
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $invoice = SalesInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->access->operation($actor, PaymentsPermissions::ALLOCATE, (int) $customer->location_id);
            app(SalesAccess::class)->authorize($actor, SalesPermissions::INVOICES_VIEW, (int) $invoice->location_id);
            $this->access->key($key);
            if ($invoice->status !== 'posted' || $invoice->cancelled_at !== null || $invoice->currency !== 'EGP'
                || (int) $invoice->location_id !== (int) $customer->location_id || ! $customer->is_active) {
                $this->access->invalid('invoice', 'Select a posted EGP invoice and an active customer in the same branch.');
            }
            if (array_diff(array_keys($terms), ['settlement_terms', 'planned_immediate_amount', 'due_date']) !== []) {
                $this->access->invalid('terms', 'Unsupported settlement terms.');
            }
            $terms = app(CustomerSettlementTerms::class)->validate($terms, $invoice->total_amount, $invoice->invoice_date->format('Y-m-d'));
            $hash = app(PaymentFingerprint::class)->make('payments', 'customer_invoice_link', ['customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'terms' => $terms, 'actor_id' => $actor->id]);
            $existing = CustomerInvoiceAccount::where('sales_invoice_id', $invoice->id)->orWhere('operation_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->access->match($existing->payload_fingerprint, $hash);

                return $existing;
            }
            if (DB::table('payment_allocations')->where('sales_invoice_id', $invoice->id)->exists()
                || Payment::where('source_document_type', 'sales_invoice')->where('source_document_reference', $invoice->uuid)->exists()) {
                $this->access->invalid('invoice', 'Historical invoice payment evidence requires explicit reconciliation before linking.');
            }
            $link = CustomerAccess::write(fn () => CustomerInvoiceAccount::create($terms + ['customer_id' => $customer->id,
                'sales_invoice_id' => $invoice->id, 'location_id' => $customer->location_id, 'operation_key' => $key,
                'payload_fingerprint' => $hash, 'created_by' => $actor->id]));
            app(AuditLogger::class)->record('customer_invoice_linked', metadata: ['customer_id' => $customer->id, 'invoice_id' => $invoice->id], actor: $actor);

            return $link;
        }, 3);
    }
}
