<?php

namespace App\Modules\Payments\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Models\CustomerInvoiceAccount;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesPermissions;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CustomerFinancials
{
    public function available(Payment $payment, ?User $actor = null, bool $locking = false): string
    {
        app(CustomerAccess::class)->operation($actor ?? auth()->user(), PaymentsPermissions::VIEW, (int) $payment->location_id);
        if ($payment->status !== 'posted') {
            return '0.0000';
        }
        $allocated = $this->sum(PaymentAllocation::where('payment_id', $payment->id)->whereNull('released_at'), 'amount', $locking);
        $refunded = $this->sum(PaymentRefund::where('payment_id', $payment->id)->where('status', 'posted')->whereNull('recovered_at'), 'amount', $locking);

        return (string) BigDecimal::of($payment->amount)->minus($allocated)->minus($refunded)->toScale(4);
    }

    public function invoice(User $actor, SalesInvoice $invoice, bool $locking = false): array
    {
        $invoice = SalesInvoice::query()->when($locking, fn ($query) => $query->lockForUpdate())->findOrFail($invoice->getKey());
        app(CustomerAccess::class)->operation($actor, PaymentsPermissions::VIEW, (int) $invoice->location_id);
        app(SalesAccess::class)->authorize($actor, SalesPermissions::INVOICES_VIEW, (int) $invoice->location_id);
        $credits = $this->sum($invoice->creditNotes()->getQuery()->where('status', 'posted')->whereNull('cancelled_at'), 'total_amount', $locking);
        $paid = $this->sum(PaymentAllocation::where('sales_invoice_id', $invoice->id)->whereNull('released_at')->whereHas('payment', fn ($q) => $q->where('status', 'posted')), 'amount', $locking);

        return $this->invoiceBalance($invoice, $credits, $paid);
    }

    /** @return array{total: string, credited: string, paid: string, outstanding: string, excess_allocation: string, payment_status: string} */
    private function invoiceBalance(SalesInvoice $invoice, string $credits, string $paid): array
    {
        $net = BigDecimal::of($invoice->total_amount)->minus($credits);
        $outstanding = $net->minus($paid);

        return ['total' => $invoice->total_amount, 'credited' => (string) BigDecimal::of($credits)->toScale(4), 'paid' => (string) BigDecimal::of($paid)->toScale(4),
            'outstanding' => (string) BigDecimal::max('0.0000', $outstanding)->toScale(4), 'excess_allocation' => (string) BigDecimal::max('0.0000', $outstanding->negated())->toScale(4),
            'payment_status' => $outstanding->isLessThanOrEqualTo(0) ? 'settled' : (BigDecimal::of($paid)->isPositive() ? 'partial' : 'unpaid')];
    }

    public function customer(User $actor, Customer $customer): array
    {
        app(CustomerAccess::class)->operation($actor, PaymentsPermissions::VIEW, (int) $customer->location_id);
        $outstanding = BigDecimal::of('0.0000');
        $advance = BigDecimal::of('0.0000');
        $invoices = SalesInvoice::query()->whereIn('id', CustomerInvoiceAccount::where('customer_id', $customer->id)->select('sales_invoice_id'))
            ->where('status', 'posted')->whereNull('cancelled_at')->get();
        foreach ($invoices->pluck('location_id')->unique() as $locationId) {
            app(CustomerAccess::class)->operation($actor, PaymentsPermissions::VIEW, (int) $locationId);
            app(SalesAccess::class)->authorize($actor, SalesPermissions::INVOICES_VIEW, (int) $locationId);
        }
        if ($invoices->isNotEmpty()) {
            $credits = SalesCreditNote::whereIn('sales_invoice_id', $invoices->modelKeys())->where('status', 'posted')->whereNull('cancelled_at')
                ->selectRaw('sales_invoice_id, SUM(total_amount) AS total')->groupBy('sales_invoice_id')->pluck('total', 'sales_invoice_id');
            $allocations = PaymentAllocation::whereIn('sales_invoice_id', $invoices->modelKeys())->whereNull('released_at')
                ->whereHas('payment', fn ($query) => $query->where('status', 'posted'))
                ->selectRaw('sales_invoice_id, SUM(amount) AS total')->groupBy('sales_invoice_id')->pluck('total', 'sales_invoice_id');
            foreach ($invoices as $invoice) {
                $balance = $this->invoiceBalance($invoice, (string) ($credits[$invoice->id] ?? '0'), (string) ($allocations[$invoice->id] ?? '0'));
                $outstanding = $outstanding->plus($balance['outstanding']);
                $advance = $advance->plus($balance['excess_allocation']);
            }
        }
        $payments = Payment::where('customer_id', $customer->id)->where('status', 'posted')->get();
        foreach ($payments->pluck('location_id')->unique() as $locationId) {
            app(CustomerAccess::class)->operation($actor, PaymentsPermissions::VIEW, (int) $locationId);
        }
        if ($payments->isNotEmpty()) {
            $allocated = PaymentAllocation::whereIn('payment_id', $payments->modelKeys())->whereNull('released_at')
                ->selectRaw('payment_id, SUM(amount) AS total')->groupBy('payment_id')->pluck('total', 'payment_id');
            $refunded = PaymentRefund::whereIn('payment_id', $payments->modelKeys())->where('status', 'posted')->whereNull('recovered_at')
                ->selectRaw('payment_id, SUM(amount) AS total')->groupBy('payment_id')->pluck('total', 'payment_id');
            foreach ($payments as $payment) {
                $advance = $advance->plus(BigDecimal::of($payment->amount)->minus($allocated[$payment->id] ?? '0')->minus($refunded[$payment->id] ?? '0'));
            }
        }

        return ['outstanding' => (string) $outstanding, 'advance' => (string) $advance, 'net_balance' => (string) $outstanding->minus($advance)];
    }

    /** Posting requires current rows even when a caller opened an older repeatable-read snapshot. */
    private function sum(\Illuminate\Database\Eloquent\Builder $query, string $field, bool $locking): string
    {
        if (! $locking) {
            return (string) $query->sum($field);
        }
        $total = BigDecimal::of('0.0000');
        foreach ($query->orderBy($query->getModel()->qualifyColumn('id'))->lockForUpdate()->get([$query->getModel()->qualifyColumn($field)]) as $record) {
            $total = $total->plus($record->getAttribute($field));
        }

        return (string) $total;
    }

    public function history(User $actor, ?int $customerId, ?string $from, ?string $until, string $search, string $kind): Builder
    {
        $customers = app(CustomerAccess::class)->scope(Customer::query(), $actor)->when($customerId !== null, fn ($q) => $q->whereKey($customerId))->select('payment_customers.id');
        $base = fn () => DB::table('payments as p')->join('payment_customers as c', 'c.id', '=', 'p.customer_id')->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')->whereIn('c.id', clone $customers);
        $receipts = $base()->selectRaw("CONCAT('P',p.id) AS event_key, 'receipt' AS kind, COALESCE(p.posted_at,p.created_at) AS event_date, p.payment_number AS code,c.name AS customer_name,c.id AS customer_id,p.status,m.name AS method,p.external_reference AS reference,NULL AS document,p.amount,CASE WHEN p.status='posted' THEN -p.amount ELSE 0 END AS signed_amount");
        $refunds = $base()->join('payment_refunds as r', 'r.payment_id', '=', 'p.id')->selectRaw("CONCAT('R',r.id), 'refund',COALESCE(r.posted_at,r.created_at),r.refund_number,c.name,c.id,r.status,m.name,r.external_reference,p.payment_number,r.amount,CASE WHEN r.status='posted' THEN r.amount ELSE 0 END");
        $allocations = $base()->join('payment_allocations as a', 'a.payment_id', '=', 'p.id')->whereNotNull('a.operation_key')->selectRaw("CONCAT('A',a.id),'allocation',a.allocated_at,a.code,c.name,c.id,'posted',m.name,NULL,p.payment_number,a.amount,0");
        $releases = $base()->join('payment_allocations as a', 'a.payment_id', '=', 'p.id')->whereNotNull('a.released_at')->selectRaw("CONCAT('L',a.id),'release',a.released_at,a.code,c.name,c.id,'posted',m.name,a.release_reason,p.payment_number,a.amount,0");
        $recoveries = $base()->join('payment_refunds as r', 'r.payment_id', '=', 'p.id')->whereNotNull('r.recovered_at')->selectRaw("CONCAT('V',r.id),'recovery',r.recovered_at,r.refund_number,c.name,c.id,'posted',m.name,r.recovery_reference,r.refund_number,r.amount,-r.amount");
        $events = $receipts->unionAll($refunds)->unionAll($allocations)->unionAll($releases)->unionAll($recoveries);
        if ($customerId !== null) {
            $customer = Customer::findOrFail($customerId);
            app(CustomerAccess::class)->operation($actor, PaymentsPermissions::VIEW, (int) $customer->location_id);
            app(SalesAccess::class)->authorize($actor, SalesPermissions::INVOICES_VIEW, (int) $customer->location_id);
        }
        $authorizedInvoices = app(SalesAccess::class)->scopeInvoices(SalesInvoice::query(), $actor)->select('sales_invoices.id');
        $invoices = DB::table('customer_invoice_accounts as l')->join('payment_customers as c', 'c.id', '=', 'l.customer_id')->join('sales_invoices as i', 'i.id', '=', 'l.sales_invoice_id')
            ->whereIn('c.id', clone $customers)->where('i.status', 'posted')->whereNull('i.cancelled_at')
            ->whereIn('i.id', clone $authorizedInvoices)->whereColumn('i.location_id', 'c.location_id')
            ->selectRaw("CONCAT('I',i.id),'invoice',GREATEST(i.posted_at,l.created_at),i.invoice_number,c.name,c.id,'posted',NULL,NULL,NULL,i.total_amount,i.total_amount");
        $credits = DB::table('customer_invoice_accounts as l')->join('payment_customers as c', 'c.id', '=', 'l.customer_id')->join('sales_credit_notes as n', 'n.sales_invoice_id', '=', 'l.sales_invoice_id')
            ->whereIn('c.id', clone $customers)->where('n.status', 'posted')->whereNull('n.cancelled_at')
            ->whereIn('n.sales_invoice_id', clone $authorizedInvoices)->whereColumn('n.location_id', 'c.location_id')
            ->selectRaw("CONCAT('C',n.id),'credit',GREATEST(n.posted_at,l.created_at),n.credit_note_number,c.name,c.id,'posted',NULL,NULL,NULL,n.total_amount,-n.total_amount");
        $events->unionAll($invoices)->unionAll($credits);
        $running = DB::query()->fromSub($events, 'events')->selectRaw('events.*, SUM(signed_amount) OVER (PARTITION BY customer_id ORDER BY event_date,event_key ROWS UNBOUNDED PRECEDING) AS running_balance');

        return DB::query()->fromSub($running, 'history')
            ->when($from, fn ($q, $v) => $q->whereDate('event_date', '>=', $v))->when($until, fn ($q, $v) => $q->whereDate('event_date', '<=', $v))
            ->when($kind !== '', fn ($q) => $q->where('kind', $kind))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('code', 'like', '%'.$search.'%')->orWhere('customer_name', 'like', '%'.$search.'%')->orWhere('reference', 'like', '%'.$search.'%')->orWhere('document', 'like', '%'.$search.'%')))
            ->orderBy('event_date')->orderBy('event_key');
    }
}
