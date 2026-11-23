<?php

namespace App\Modules\Reporting\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PosReportingEvidence
{
    public const FIELDS = ['sale_count', 'sale_total', 'sale_revenue', 'return_count', 'credit_count', 'credit_total', 'refund_count', 'refund_total', 'refund_recovery_total', 'opened_count', 'closed_count', 'opening_cash', 'expected_cash', 'counted_cash', 'variance', 'missing_close', 'missing_sale', 'received', 'processing', 'completed', 'rejected', 'conflict'];

    /** Persisted server evidence only. A synced return request is not a posted financial return. */
    public function daily(int $location, string $date): Builder
    {
        $parts = [];
        $transactions = DB::table('pos_transactions as t')->join('pos_sessions as s', 's.id', '=', 't.pos_session_id')
            ->whereColumn('t.location_id', 's.location_id')->whereColumn('t.pos_device_id', 's.pos_device_id')->whereColumn('t.cashier_id', 's.opened_by')
            ->where('s.location_id', $location);
        $sales = (clone $transactions)->join('sales_invoices as i', 'i.id', '=', 't.sales_invoice_id')
            ->where('t.sync_status', 'completed')->where('t.transaction_type', 'sale')->where('i.status', 'posted')->whereNull('i.cancelled_at')
            ->whereColumn('i.location_id', 's.location_id')->whereNotNull('i.posted_at')
            ->where('t.id', '=', DB::raw("(SELECT MIN(p.id) FROM pos_transactions p WHERE p.sales_invoice_id = i.id AND p.sync_status = 'completed' AND p.transaction_type = 'sale')"));
        $parts[] = $this->part($sales, 'i.posted_at', $date, ['sale_count' => '1', 'sale_total' => 'i.total_amount', 'sale_revenue' => 'i.subtotal - i.discount_amount']);
        $sync = (clone $transactions)->leftJoin('sales_invoices as i', 'i.id', '=', 't.sales_invoice_id');
        $syncFields = [];
        foreach (['received', 'processing', 'completed', 'rejected', 'conflict'] as $status) {
            $syncFields[$status] = "CASE WHEN t.sync_status = '$status' THEN 1 ELSE 0 END";
        }
        $syncFields['missing_sale'] = "CASE WHEN t.sync_status = 'completed' AND t.transaction_type = 'sale' AND (i.id IS NULL OR i.status <> 'posted' OR i.cancelled_at IS NOT NULL OR i.location_id <> s.location_id) THEN 1 ELSE 0 END";
        $parts[] = $this->part($sync, 'COALESCE(t.server_received_at,t.created_at)', $date, $syncFields);
        $returns = $this->returns($location);
        $parts[] = $this->part(clone $returns, 'r.posted_at', $date, ['return_count' => '1']);
        $credits = $returns->join('sales_credit_notes as c', 'c.sales_return_id', '=', 'r.id')->where('c.status', 'posted')->whereNull('c.cancelled_at')->whereColumn('c.location_id', 's.location_id');
        $parts[] = $this->part($credits, 'c.posted_at', $date, ['credit_count' => '1', 'credit_total' => 'c.total_amount']);
        $refunds = DB::table('payment_refunds as r')->join('pos_sessions as s', 's.id', '=', 'r.pos_session_id')
            ->join('treasury_operations as o', 'o.id', '=', 'r.treasury_operation_id')->where('r.status', 'posted')->where('o.status', 'posted')->where('s.location_id', $location)
            ->whereColumn('o.account_id', 'r.treasury_account_id')->whereColumn('o.amount', 'r.amount');
        $parts[] = $this->part(clone $refunds, 'r.posted_at', $date, ['refund_count' => '1', 'refund_total' => 'r.amount']);
        $recovered = $refunds->join('treasury_operations as recovery', 'recovery.id', '=', 'r.recovery_operation_id')->where('recovery.status', 'posted')->whereColumn('recovery.reversal_of_id', 'o.id');
        $parts[] = $this->part($recovered, 'recovery.posted_at', $date, ['refund_recovery_total' => 'r.amount']);
        $sessions = DB::table('pos_sessions as s')->where('s.location_id', $location);
        $parts[] = $this->part(clone $sessions, 's.opened_at', $date, ['opened_count' => '1', 'opening_cash' => 's.opening_cash']);
        $parts[] = $this->part($sessions->where('s.status', 'closed'), 's.closed_at', $date, ['closed_count' => '1', 'expected_cash' => 'COALESCE(s.expected_cash,0)',
            'counted_cash' => 'COALESCE(s.counted_cash,0)', 'variance' => 'COALESCE(s.cash_difference,0)',
            'missing_close' => 'CASE WHEN s.expected_cash IS NULL OR s.counted_cash IS NULL OR s.cash_difference IS NULL THEN 1 ELSE 0 END']);
        $events = array_shift($parts);
        foreach ($parts as $part) {
            $events->unionAll($part);
        }
        $query = DB::query()->fromSub($events, 'events')->select('session_id', 'cashier_id', 'device_id', 'currency')->groupBy('session_id', 'cashier_id', 'device_id', 'currency');
        foreach (self::FIELDS as $field) {
            $query->selectRaw('SUM('.$field.') AS '.$field);
        }

        return $query->orderBy('session_id')->orderBy('cashier_id');
    }

    /** Return-request shift when persisted; otherwise the authoritative originating POS sale shift. */
    public function returns(?int $location = null): Builder
    {
        return DB::table('sales_returns as r')
            ->join('pos_transactions as sale', fn ($join) => $join->on('sale.sales_order_id', '=', 'r.sales_order_id')->where('sale.transaction_type', 'sale')->where('sale.sync_status', 'completed'))
            ->leftJoin('pos_transactions as request', fn ($join) => $join->on('request.sales_return_id', '=', 'r.id')->where('request.transaction_type', 'return'))
            ->join('pos_sessions as s', 's.id', '=', DB::raw('COALESCE(request.pos_session_id,sale.pos_session_id)'))
            ->where('sale.id', DB::raw("(SELECT MIN(p.id) FROM pos_transactions p WHERE p.sales_order_id = r.sales_order_id AND p.transaction_type = 'sale' AND p.sync_status = 'completed')"))
            ->where('r.status', 'posted')->whereColumn('r.location_id', 's.location_id')
            ->when($location !== null, fn ($query) => $query->where('s.location_id', $location));
    }

    /** @param array<string,string> $values */
    private function part(Builder $query, string $dateColumn, string $date, array $values): Builder
    {
        $end = CarbonImmutable::parse($date)->addDay()->toDateString().' 00:00:00';
        $query->whereRaw("$dateColumn >= ? AND $dateColumn < ?", [$date.' 00:00:00', $end])
            ->selectRaw('s.id AS session_id,s.opened_by AS cashier_id,s.pos_device_id AS device_id,s.currency');
        foreach (self::FIELDS as $field) {
            $query->selectRaw('('.($values[$field] ?? '0').') AS '.$field);
        }

        return $query;
    }
}
