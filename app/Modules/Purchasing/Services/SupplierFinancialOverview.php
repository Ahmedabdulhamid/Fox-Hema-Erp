<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SupplierFinancialOverview
{
    public function __construct(private readonly VendorPaymentAccess $access) {}

    /**
     * All financial pages and aggregates share a snapshot and inclusive recognition cutoff.
     *
     * @return array{supplier: Supplier, locations: array<int, string>, totals: array<string, string>, statement: LengthAwarePaginator, opening: string, cutoff: string, bills: Collection, advances: Collection, recent_activity: Collection}
     */
    public function report(User $actor, int $supplierId, ?int $locationId = null, ?string $cutoff = null, ?string $from = null, int $page = 1): array
    {
        $cutoff ??= now()->toDateString();
        Validator::make(compact('cutoff', 'from', 'page'), [
            'cutoff' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:cutoff'],
            'page' => ['integer', 'min:1', 'max:1000000'],
        ])->validate();

        if ($locationId !== null) {
            $this->access->authorize($actor, PurchasingPermissions::PAYMENTS_VIEW, $locationId);
        }
        $locations = $this->access->scope(Location::query(), $actor, 'locations.id')->orderBy('name')->pluck('name', 'id')->all();
        $supplier = Supplier::findOrFail($supplierId);
        $orders = PurchaseOrder::whereIn('location_id', array_keys($locations))
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))->select('id');

        return DB::transaction(function () use ($supplier, $supplierId, $orders, $locations, $locationId, $cutoff, $from, $page): array {
            $end = $cutoff.' 23:59:59';
            $bills = DB::table('vendor_bills as b')->join('purchase_orders as o', 'o.id', '=', 'b.purchase_order_id')
                ->where('b.supplier_id', $supplierId)->whereIn('o.id', $orders)
                ->whereColumn('o.supplier_id', 'b.supplier_id')->where('b.status', 'posted')
                ->where(fn ($query) => $query->where('b.posted_at', '<=', $end)->orWhereNull('b.posted_at'));
            $payments = DB::table('vendor_payments as p')->where('p.supplier_id', $supplierId)
                ->whereIn('p.location_id', array_keys($locations))->when($locationId !== null, fn ($query) => $query->where('p.location_id', $locationId))
                ->whereIn('p.status', ['posted', 'void'])->where(fn ($query) => $query->where('p.posted_at', '<=', $end)->orWhereNull('p.posted_at'));
            $this->assertEvidence($supplierId, $bills, $payments);
            $activePayments = (clone $payments)->where(fn ($query) => $query->whereNull('p.voided_at')->orWhere('p.voided_at', '>', $end));
            $allocations = DB::table('vendor_payment_allocations as a')
                ->joinSub((clone $activePayments)->select('p.id', 'p.supplier_id', 'p.location_id'), 'p', 'p.id', '=', 'a.vendor_payment_id')
                ->joinSub((clone $bills)->select('b.id', 'b.supplier_id', 'o.location_id'), 'b', 'b.id', '=', 'a.vendor_bill_id')
                ->whereColumn('a.supplier_id', 'p.supplier_id')->whereColumn('b.supplier_id', 'p.supplier_id')->whereColumn('b.location_id', 'p.location_id')
                ->whereRaw('COALESCE(a.allocated_at, a.created_at) <= ?', [$end])
                ->where(fn ($query) => $query->whereNull('a.released_at')->orWhere('a.released_at', '>', $end));
            $byBill = (clone $allocations)->selectRaw('a.vendor_bill_id, SUM(a.amount) as paid')->groupBy('a.vendor_bill_id');
            $byPayment = (clone $allocations)->selectRaw('a.vendor_payment_id, SUM(a.amount) as allocated')->groupBy('a.vendor_payment_id');
            $billAmounts = (clone $bills)->leftJoinSub($byBill, 'settled', 'settled.vendor_bill_id', '=', 'b.id')
                ->select('b.*', 'o.location_id')->selectRaw('COALESCE(settled.paid, 0.0000) as paid, b.total_amount - COALESCE(settled.paid, 0.0000) as outstanding');
            $paymentAmounts = (clone $activePayments)->leftJoinSub($byPayment, 'used', 'used.vendor_payment_id', '=', 'p.id')
                ->select('p.*')->selectRaw('COALESCE(used.allocated, 0.0000) as allocated, p.amount - COALESCE(used.allocated, 0.0000) as unallocated');
            $billTotals = DB::query()->fromSub($billAmounts, 'b')->selectRaw('COALESCE(SUM(total_amount), 0.0000) as billed, COALESCE(SUM(paid), 0.0000) as allocated, COALESCE(SUM(outstanding), 0.0000) as gross_open_invoices, COALESCE(SUM(CASE WHEN due_date < ? THEN outstanding ELSE 0 END), 0.0000) as overdue, MIN(outstanding) as minimum', [$cutoff])->first();
            $paymentTotals = DB::query()->fromSub($paymentAmounts, 'p')->selectRaw('COALESCE(SUM(amount), 0.0000) as payments, COALESCE(SUM(unallocated), 0.0000) as unallocated_advances, MIN(unallocated) as minimum')->first();
            if (($billTotals->minimum !== null && BigDecimal::of($billTotals->minimum)->isNegative()) || ($paymentTotals->minimum !== null && BigDecimal::of($paymentTotals->minimum)->isNegative())) {
                $this->unreconciled();
            }
            $totals = [];
            foreach (['billed', 'allocated', 'gross_open_invoices', 'overdue'] as $key) {
                $totals[$key] = $this->decimal($billTotals->{$key});
            }
            foreach (['payments', 'unallocated_advances'] as $key) {
                $totals[$key] = $this->decimal($paymentTotals->{$key});
            }
            $totals['net_supplier_balance'] = $this->decimal(BigDecimal::of($totals['gross_open_invoices'])->minus($totals['unallocated_advances']));
            $events = $this->events($bills, $payments, $end);
            $running = DB::query()->fromSub($events, 'events')->select('events.*')
                ->selectRaw('SUM(movement) OVER (ORDER BY recognized_at, event_order, event_id ROWS UNBOUNDED PRECEDING) as running_balance');
            $statement = DB::query()->fromSub($running, 'statement')
                ->when($from !== null, fn ($query) => $query->where('recognized_at', '>=', $from.' 00:00:00'))
                ->orderBy('recognized_at')->orderBy('event_order')->orderBy('event_id')->paginate(25, ['*'], 'statementPage', $page);
            $opening = $from === null ? '0.0000' : $this->decimal(DB::query()->fromSub($events, 'opening')->where('recognized_at', '<', $from.' 00:00:00')->sum('movement'));

            return compact('supplier', 'locations', 'totals', 'statement', 'opening', 'cutoff') + [
                'recent_activity' => DB::query()->fromSub($events, 'recent')->orderByDesc('recognized_at')->orderByDesc('event_order')->orderByDesc('event_id')->limit(10)->get(),
                'bills' => DB::query()->fromSub($billAmounts, 'b')->orderBy('outstanding', 'desc')->orderByDesc('id')->limit(25)->get(),
                'advances' => DB::query()->fromSub($paymentAmounts, 'p')->where('unallocated', '>', 0)->orderByDesc('id')->limit(25)->get(),
            ];
        });
    }

    /** Financial movements plus zero-value allocation audit activity. */
    private function events(Builder $bills, Builder $payments, string $end): Builder
    {
        $events = (clone $bills)->selectRaw("b.id as event_id, 1 as event_order, b.posted_at as recognized_at, b.bill_date as document_date, 'bill' as entry_type, b.bill_number as reference, b.uuid as bill_uuid, NULL as payment_uuid, o.location_id, b.total_amount as movement, b.total_amount as activity_amount");
        $events->unionAll((clone $payments)->selectRaw("p.id, 2, p.posted_at, p.payment_date, 'payment', p.payment_number, NULL, p.uuid, p.location_id, -p.amount, p.amount"));
        $events->unionAll((clone $payments)->where('p.status', 'void')->where('p.voided_at', '<=', $end)
            ->whereNotNull('p.reversal_key')->whereNotNull('p.reversal_fingerprint')
            ->selectRaw("p.id, 5, p.voided_at, DATE(p.voided_at), CASE WHEN p.recovery_reference IS NULL THEN 'reversal' ELSE 'recovery' END, p.payment_number, NULL, p.uuid, p.location_id, p.amount, p.amount"));
        $activity = DB::table('vendor_payment_allocations as a')
            ->joinSub((clone $payments)->select('p.id', 'p.uuid', 'p.payment_number', 'p.location_id'), 'p', 'p.id', '=', 'a.vendor_payment_id')
            ->joinSub((clone $bills)->select('b.id', 'b.uuid'), 'b', 'b.id', '=', 'a.vendor_bill_id');
        $events->unionAll((clone $activity)->whereRaw('COALESCE(a.allocated_at, a.created_at) <= ?', [$end])
            ->selectRaw("a.id, 3, COALESCE(a.allocated_at, a.created_at), DATE(COALESCE(a.allocated_at, a.created_at)), 'allocation', p.payment_number, b.uuid, p.uuid, p.location_id, CAST(0 AS DECIMAL(20,4)), a.amount"));
        $events->unionAll((clone $activity)->where('a.released_at', '<=', $end)
            ->selectRaw("a.id, 4, a.released_at, DATE(a.released_at), 'release', p.payment_number, b.uuid, p.uuid, p.location_id, CAST(0 AS DECIMAL(20,4)), a.amount"));

        return $events;
    }

    /** Ownership-ambiguous legacy evidence is never silently assigned to a location. */
    private function assertEvidence(int $supplierId, Builder $bills, Builder $payments): void
    {
        if ((clone $bills)->whereNull('b.posted_at')->exists()) {
            $this->unreconciled();
        }
        if (DB::table('vendor_payments')->where('supplier_id', $supplierId)->whereIn('status', ['posted', 'void'])->whereNull('location_id')->exists()
            || DB::table('vendor_bills as b')->leftJoin('purchase_orders as o', 'o.id', '=', 'b.purchase_order_id')->where('b.supplier_id', $supplierId)->where('b.status', 'posted')
                ->where(fn ($query) => $query->whereNull('o.id')->orWhereColumn('o.supplier_id', '!=', 'b.supplier_id'))->exists()) {
            $this->unreconciled();
        }
        if ((clone $payments)->where(fn ($query) => $query->whereNull('p.posted_at')->orWhereNull('p.payment_method_id')->orWhereNull('p.currency')->orWhere('p.currency', '!=', 'EGP')->orWhereNull('p.idempotency_key')->orWhereNull('p.payload_fingerprint')
            ->orWhere(fn ($query) => $query->where('p.status', 'void')->where(fn ($query) => $query->whereNull('p.voided_at')->orWhereNull('p.reversal_key')->orWhereNull('p.reversal_fingerprint')))
            ->orWhere(fn ($query) => $query->where('p.status', 'posted')->whereNotNull('p.voided_at')))->exists()) {
            $this->unreconciled();
        }
        $evidence = DB::table('vendor_payment_allocations as a')->join('vendor_payments as p', 'p.id', '=', 'a.vendor_payment_id')
            ->leftJoin('vendor_bills as b', 'b.id', '=', 'a.vendor_bill_id')->leftJoin('purchase_orders as o', 'o.id', '=', 'b.purchase_order_id')
            ->whereIn('p.id', (clone $payments)->select('p.id'))
            ->where(fn ($query) => $query->whereNull('b.id')->orWhere('b.status', '!=', 'posted')->orWhereNull('o.id')
                ->orWhereColumn('a.supplier_id', '!=', 'p.supplier_id')->orWhereColumn('b.supplier_id', '!=', 'p.supplier_id')
                ->orWhereColumn('o.supplier_id', '!=', 'p.supplier_id')->orWhereColumn('o.location_id', '!=', 'p.location_id'));
        if ($evidence->exists()) {
            $this->unreconciled();
        }
    }

    private function unreconciled(): never
    {
        throw ValidationException::withMessages(['supplier' => __('Unreconciled legacy financial ownership prevents a reliable supplier balance.')]);
    }

    private function decimal(mixed $amount): string
    {
        return (string) BigDecimal::of($amount)->toScale(4);
    }
}
