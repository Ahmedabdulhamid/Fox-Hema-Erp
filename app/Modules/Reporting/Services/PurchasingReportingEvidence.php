<?php

namespace App\Modules\Reporting\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Separate document headers from lines: header amounts must never be repeated per variant. */
class PurchasingReportingEvidence
{
    public const FIELDS = ['document_count', 'quantity', 'amount', 'remaining', 'missing', 'partial_count', 'complete_count'];

    public function allocations(string $column): Builder
    {
        return DB::table('vendor_payment_allocations as a')
            ->join('vendor_payments as p', 'p.id', '=', 'a.vendor_payment_id')
            ->join('vendor_bills as b', 'b.id', '=', 'a.vendor_bill_id')
            ->leftJoin('purchase_orders as o', 'o.id', '=', 'b.purchase_order_id')
            ->whereNull('a.released_at')->where('p.status', 'posted')->whereNull('p.voided_at')
            ->selectRaw('a.'.$column.' AS owner_id, SUM(a.amount) AS allocated,
                SUM(CASE WHEN b.status <> "posted" OR o.id IS NULL OR o.supplier_id <> b.supplier_id
                OR p.supplier_id <> b.supplier_id OR a.supplier_id <> p.supplier_id
                OR p.location_id IS NULL OR p.location_id <> o.location_id OR p.currency IS NULL OR p.currency <> "EGP"
                OR p.payment_method_id IS NULL OR p.idempotency_key IS NULL OR p.payload_fingerprint IS NULL
                THEN 1 ELSE 0 END) AS missing')->groupBy('a.'.$column);
    }

    public function bills(int $locationId): Builder
    {
        return DB::table('vendor_bills as b')->join('purchase_orders as o', 'o.id', '=', 'b.purchase_order_id')
            ->leftJoinSub($this->allocations('vendor_bill_id'), 'a', 'a.owner_id', '=', 'b.id')
            ->where('o.location_id', $locationId)->where('b.status', 'posted');
    }

    public function payments(int $locationId): Builder
    {
        return DB::table('vendor_payments as p')
            ->leftJoinSub($this->allocations('vendor_payment_id'), 'a', 'a.owner_id', '=', 'p.id')
            ->where('p.location_id', $locationId)->whereIn('p.status', ['posted', 'void']);
    }

    public function received(): Builder
    {
        return DB::table('purchase_receipt_items as ri')->join('purchase_receipts as r', 'r.id', '=', 'ri.purchase_receipt_id')
            ->where('r.status', 'posted')->selectRaw('ri.purchase_order_item_id, SUM(ri.quantity) AS received')
            ->groupBy('ri.purchase_order_item_id');
    }

    /** @return array<string,Builder> */
    public function sources(): array
    {
        return [
            'location_days' => DB::table('locations')->where('is_active', true)
                ->selectRaw('id,id AS location_id,? AS report_date', [today()->toDateString()]),
            'orders' => DB::table('purchase_orders')->selectRaw('id, location_id, order_date AS report_date'),
            'receipts' => DB::table('purchase_receipts as r')->join('purchase_orders as o', 'o.id', '=', 'r.purchase_order_id')
                ->selectRaw('r.id,o.location_id,DATE(COALESCE(r.posted_at,o.order_date)) AS report_date'),
            'bills' => DB::table('vendor_bills as b')->join('purchase_orders as o', 'o.id', '=', 'b.purchase_order_id')
                ->selectRaw('b.id,o.location_id,DATE(COALESCE(b.posted_at,b.bill_date)) AS report_date'),
            'payments' => DB::table('vendor_payments')->whereNotNull('location_id')->selectRaw('id,location_id,payment_date AS report_date'),
            'recoveries' => DB::table('vendor_payments')->whereNotNull('location_id')->whereNotNull('voided_at')
                ->selectRaw('id,location_id,DATE(voided_at) AS report_date'),
            'partitions' => DB::table('reporting_purchasing_partitions')->select('id', 'location_id', 'report_date'),
        ];
    }

    /** @return array<string,Builder> */
    public function daily(int $locationId, string $date): array
    {
        $orders = DB::table('purchase_orders as o')->where('o.location_id', $locationId)->where('o.order_date', $date);
        $receipts = DB::table('purchase_receipts as r')->join('purchase_orders as o', 'o.id', '=', 'r.purchase_order_id')
            ->where('o.location_id', $locationId)->where('r.status', 'posted')
            ->whereRaw('COALESCE(r.posted_at,o.order_date) >= ? AND COALESCE(r.posted_at,o.order_date) < DATE_ADD(?, INTERVAL 1 DAY)', [$date, $date]);
        $bills = $this->bills($locationId)->whereRaw('COALESCE(b.posted_at,b.bill_date) >= ? AND COALESCE(b.posted_at,b.bill_date) < DATE_ADD(?, INTERVAL 1 DAY)', [$date, $date]);
        $payments = $this->payments($locationId)->where('p.payment_date', $date);
        $missingPayment = 'p.currency IS NULL OR p.currency <> "EGP" OR p.payment_method_id IS NULL OR p.idempotency_key IS NULL OR p.payload_fingerprint IS NULL OR p.posted_at IS NULL';
        $receiptProgress = DB::table('purchase_receipt_items')->selectRaw('purchase_receipt_id,
            SUM(CASE WHEN expected_quantity IS NULL THEN 1 ELSE 0 END) AS missing,
            SUM(CASE WHEN quantity < expected_quantity THEN 1 ELSE 0 END) AS partial')->groupBy('purchase_receipt_id');

        return [
            'order' => $this->group(clone $orders, 'o.supplier_id', 'o.status', '0', 'COUNT(*)', '0', 'SUM(o.total_amount)', '0', '0'),
            'order_line' => $this->group((clone $orders)->join('purchase_order_items as i', 'i.purchase_order_id', '=', 'o.id')
                ->leftJoinSub($this->received(), 'r', 'r.purchase_order_item_id', '=', 'i.id'),
                'o.supplier_id', 'o.status', 'i.variant_id', '0', 'SUM(i.quantity)', 'SUM(i.line_total)',
                'SUM(CASE WHEN o.status IN ("confirmed","partially_received","received") THEN i.quantity-COALESCE(r.received,0) ELSE 0 END)',
                'SUM(CASE WHEN COALESCE(r.received,0)>i.quantity THEN 1 ELSE 0 END)'),
            'receipt' => $this->group((clone $receipts)->leftJoinSub($receiptProgress, 'progress', 'progress.purchase_receipt_id', '=', 'r.id'),
                'o.supplier_id', '"posted"', '0', 'COUNT(*)', '0', '0', '0',
                'SUM(CASE WHEN r.posted_at IS NULL OR r.location_id <> o.location_id OR progress.missing IS NULL OR progress.missing > 0 THEN 1 ELSE 0 END)',
                'SUM(CASE WHEN progress.missing=0 AND progress.partial>0 THEN 1 ELSE 0 END)',
                'SUM(CASE WHEN progress.missing=0 AND progress.partial=0 THEN 1 ELSE 0 END)'),
            'receipt_line' => $this->group((clone $receipts)->join('purchase_receipt_items as i', 'i.purchase_receipt_id', '=', 'r.id')
                ->join('purchase_order_items as oi', 'oi.id', '=', 'i.purchase_order_item_id'),
                'o.supplier_id', '"posted"', 'oi.variant_id', '0', 'SUM(i.quantity)', 'SUM(ROUND(i.quantity*i.unit_cost,4))', '0',
                'SUM(CASE WHEN r.posted_at IS NULL OR r.location_id<>o.location_id OR i.purchase_order_id<>o.id OR oi.purchase_order_id<>o.id OR (i.quantity>0 AND i.stock_movement_id IS NULL) THEN 1 ELSE 0 END)'),
            'bill' => $this->group(clone $bills, 'b.supplier_id', '"posted"', '0', 'COUNT(*)', '0', 'SUM(b.total_amount)', 'SUM(b.total_amount-COALESCE(a.allocated,0))',
                'SUM(CASE WHEN b.posted_at IS NULL OR b.supplier_id<>o.supplier_id OR COALESCE(a.missing,0)>0 OR COALESCE(a.allocated,0)>b.total_amount THEN 1 ELSE 0 END)'),
            'bill_line' => $this->group((clone $bills)->join('vendor_bill_items as i', 'i.vendor_bill_id', '=', 'b.id'),
                'b.supplier_id', '"posted"', 'COALESCE(i.variant_id,0)', '0', 'SUM(i.quantity)', 'SUM(i.line_total)', '0',
                'SUM(CASE WHEN b.posted_at IS NULL OR b.supplier_id<>o.supplier_id THEN 1 ELSE 0 END)'),
            'payment' => $this->group($payments, 'p.supplier_id', '"posted"', '0', 'COUNT(*)', '0', 'SUM(p.amount)', '0', 'SUM(CASE WHEN '.$missingPayment.' THEN 1 ELSE 0 END)'),
            'recovery' => $this->group($this->payments($locationId)->whereNotNull('p.voided_at')->where('p.status', 'void')->whereDate('p.voided_at', $date),
                'p.supplier_id', '"void"', '0', 'COUNT(*)', '0', 'SUM(p.amount)', '0',
                'SUM(CASE WHEN '.$missingPayment.' OR (p.treasury_account_id IS NOT NULL AND p.recovery_reference IS NULL) THEN 1 ELSE 0 END)'),
        ];
    }

    private function group(Builder $query, string $supplier, string $status, string $variant, string $count, string $quantity, string $amount, string $remaining, string $missing, string $partial = '0', string $complete = '0'): Builder
    {
        return $query->selectRaw($supplier.' AS supplier_id, "EGP" AS currency, '.$status.' AS status, '.$variant.' AS variant_id,
            '.$count.' AS document_count, '.$quantity.' AS quantity, '.$amount.' AS amount, '.$remaining.' AS remaining,
            '.$missing.' AS missing, '.$partial.' AS partial_count, '.$complete.' AS complete_count')
            ->groupByRaw(implode(',', array_filter([$supplier, str_starts_with($status, '"') ? null : $status, $variant === '0' ? null : $variant])));
    }

    /** Current balances follow SupplierFinancialBalances, independently of the selected activity period. */
    public function balances(int $locationId): Builder
    {
        $bills = $this->bills($locationId)->selectRaw('b.supplier_id,SUM(b.total_amount) AS billed,SUM(COALESCE(a.allocated,0)) AS paid,
            SUM(b.total_amount-COALESCE(a.allocated,0)) AS outstanding,0 AS advances,
            SUM(CASE WHEN b.supplier_id<>o.supplier_id OR COALESCE(a.missing,0)>0 OR COALESCE(a.allocated,0)>b.total_amount THEN 1 ELSE 0 END) AS missing')->groupBy('b.supplier_id');
        $payments = $this->payments($locationId)->where('p.status', 'posted')->whereNull('p.voided_at')
            ->selectRaw('p.supplier_id,0 AS billed,0 AS paid,0 AS outstanding,SUM(p.amount-COALESCE(a.allocated,0)) AS advances,
                SUM(CASE WHEN p.currency IS NULL OR p.currency<>"EGP" OR p.payment_method_id IS NULL OR p.idempotency_key IS NULL OR p.payload_fingerprint IS NULL
                OR COALESCE(a.missing,0)>0 OR COALESCE(a.allocated,0)>p.amount THEN 1 ELSE 0 END) AS missing')->groupBy('p.supplier_id');
        $legacy = DB::table('vendor_bills as b')->leftJoin('purchase_orders as o', 'o.id', '=', 'b.purchase_order_id')
            ->where('b.status', 'posted')->where(fn ($q) => $q->whereNull('o.id')->orWhereColumn('o.supplier_id', '<>', 'b.supplier_id'))->select('b.supplier_id')
            ->union(DB::table('vendor_payments')->where('status', 'posted')->whereNull('voided_at')
                ->where(fn ($q) => $q->whereNull('location_id')->orWhereNull('currency')->orWhereNull('payment_method_id')->orWhereNull('idempotency_key')->orWhereNull('payload_fingerprint'))
                ->select('supplier_id'));
        $suppliers = DB::table('purchase_orders')->where('location_id', $locationId)
            ->selectRaw('supplier_id,0 AS billed,0 AS paid,0 AS outstanding,0 AS advances,0 AS missing')->groupBy('supplier_id');

        return DB::query()->fromSub($bills->unionAll($payments)->unionAll($suppliers), 'totals')
            ->selectRaw('supplier_id,"EGP" AS currency,SUM(billed) AS billed,SUM(paid) AS paid,SUM(outstanding) AS outstanding,
                SUM(advances) AS advances,SUM(outstanding-advances) AS net_balance,
                SUM(missing) + CASE WHEN supplier_id IN ('.$legacy->toSql().') THEN 1 ELSE 0 END AS missing')
            ->addBinding($legacy->getBindings(), 'select')->groupBy('supplier_id');
    }
}
