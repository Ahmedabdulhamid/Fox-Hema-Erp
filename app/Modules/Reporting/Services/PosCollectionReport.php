<?php

namespace App\Modules\Reporting\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class PosCollectionReport
{
    /**
     * @param  list<int>  $locations
     * @return array{total: string, cash: string, non_cash: string, methods: list<array{id: int, code: string, name: string, type: string, amount: string}>}
     */
    public function summarize(array $locations, string $from, string $until, string $currency = 'EGP'): array
    {
        if ($locations === [] || $currency !== 'EGP') {
            return ['total' => '0.0000', 'cash' => '0.0000', 'non_cash' => '0.0000', 'methods' => []];
        }

        $receipts = DB::table('payments as p')
            ->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->join('pos_transactions as t', 't.id', '=', 'p.pos_transaction_id')
            ->join('pos_sessions as s', 's.id', '=', 'p.pos_session_id')
            ->join('sales_invoices as i', 'i.uuid', '=', 'p.source_document_reference')
            ->whereIn('i.location_id', $locations)
            ->whereColumn('p.pos_session_id', 't.pos_session_id')
            ->whereColumn('p.location_id', 'i.location_id')
            ->whereColumn('t.location_id', 'i.location_id')
            ->whereColumn('t.sales_invoice_id', 'i.id')
            ->whereColumn('s.location_id', 'i.location_id')
            ->whereColumn('s.pos_device_id', 't.pos_device_id')
            ->whereColumn('s.opened_by', 't.cashier_id')
            ->whereColumn('p.created_by', 't.cashier_id')
            ->where('t.id', DB::raw("(SELECT MIN(source.id) FROM pos_transactions source WHERE source.sales_invoice_id = i.id AND source.sync_status = 'completed' AND source.transaction_type = 'sale')"))
            ->where('p.status', 'posted')
            ->where('p.direction', 'in')
            ->where('p.currency', 'EGP')
            ->where('p.source_document_type', 'sales_invoice')
            ->where('t.transaction_type', 'sale')
            ->where('t.sync_status', 'completed')
            ->where('i.status', 'posted')
            ->whereNull('i.cancelled_at')
            ->whereBetween('p.payment_date', [$from, $until])
            ->selectRaw('m.id AS payment_method_id,m.code,m.name,m.type,p.amount AS amount');
        $refunds = DB::table('payment_refunds as r')
            ->join('payments as p', 'p.id', '=', 'r.payment_id')
            ->join('payment_methods as m', 'm.id', '=', 'r.payment_method_id')
            ->join('pos_transactions as t', 't.id', '=', 'p.pos_transaction_id')
            ->join('pos_sessions as s', 's.id', '=', 'p.pos_session_id')
            ->join('sales_invoices as i', 'i.uuid', '=', 'p.source_document_reference')
            ->join('sales_credit_notes as c', 'c.id', '=', 'r.sales_credit_note_id')
            ->whereIn('i.location_id', $locations)
            ->whereColumn('p.pos_session_id', 't.pos_session_id')
            ->whereColumn('p.location_id', 'i.location_id')
            ->whereColumn('t.location_id', 'i.location_id')
            ->whereColumn('t.sales_invoice_id', 'i.id')
            ->whereColumn('s.location_id', 'i.location_id')
            ->whereColumn('s.pos_device_id', 't.pos_device_id')
            ->whereColumn('s.opened_by', 't.cashier_id')
            ->whereColumn('p.created_by', 't.cashier_id')
            ->whereColumn('r.payment_method_id', 'p.payment_method_id')
            ->whereColumn('c.sales_invoice_id', 'i.id')
            ->where('t.id', DB::raw("(SELECT MIN(source.id) FROM pos_transactions source WHERE source.sales_invoice_id = i.id AND source.sync_status = 'completed' AND source.transaction_type = 'sale')"))
            ->where('p.status', 'posted')
            ->where('p.direction', 'in')
            ->where('p.currency', 'EGP')
            ->where('t.transaction_type', 'sale')
            ->where('t.sync_status', 'completed')
            ->where('i.status', 'posted')
            ->whereNull('i.cancelled_at')
            ->where('c.status', 'posted')
            ->whereNull('c.cancelled_at')
            ->where('r.status', 'posted')
            ->whereNull('r.recovered_at')
            ->whereBetween('r.refund_date', [$from, $until])
            ->selectRaw('m.id AS payment_method_id,m.code,m.name,m.type,-r.amount AS amount');
        $entries = $receipts->unionAll($refunds);
        $methods = DB::query()->fromSub($entries, 'collections')
            ->selectRaw('payment_method_id,code,name,type,SUM(amount) AS amount')
            ->groupBy('payment_method_id', 'code', 'name', 'type')
            ->orderBy('payment_method_id')
            ->get();
        $total = BigDecimal::zero()->toScale(4);
        $cash = BigDecimal::zero()->toScale(4);
        $nonCash = BigDecimal::zero()->toScale(4);
        $rows = [];

        foreach ($methods as $method) {
            $amount = BigDecimal::of((string) $method->amount)->toScale(4);
            $total = $total->plus($amount)->toScale(4);
            if ($method->type === 'cash') {
                $cash = $cash->plus($amount)->toScale(4);
            } else {
                $nonCash = $nonCash->plus($amount)->toScale(4);
            }
            $rows[] = [
                'id' => (int) $method->payment_method_id,
                'code' => $method->code,
                'name' => $method->name,
                'type' => $method->type,
                'amount' => (string) $amount,
            ];
        }

        return [
            'total' => (string) $total,
            'cash' => (string) $cash,
            'non_cash' => (string) $nonCash,
            'methods' => $rows,
        ];
    }
}
