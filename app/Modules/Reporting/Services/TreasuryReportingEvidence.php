<?php

namespace App\Modules\Reporting\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class TreasuryReportingEvidence
{
    /** Posted ledger legs, including compensating reversals. Counterpart account details are never selected. */
    public function entries(): Builder
    {
        $type = 'COALESCE(original.type,o.type)';
        $refund = "EXISTS(SELECT 1 FROM payment_refunds r WHERE r.status = 'posted' AND r.treasury_operation_id = COALESCE(o.reversal_of_id,o.id))";
        $category = "CASE WHEN $type = 'transfer' THEN 'internal' WHEN $type = 'opening_balance' THEN 'opening'
            WHEN $refund THEN 'refund' WHEN $type = 'payment_receipt' THEN 'receipt'
            WHEN $type = 'payment_disbursement' THEN 'payment' ELSE 'external' END";

        return DB::table('treasury_entries as e')->join('treasury_operations as o', 'o.id', '=', 'e.operation_id')
            ->leftJoin('treasury_operations as original', 'original.id', '=', 'o.reversal_of_id')
            ->where('o.status', 'posted')->whereNotNull('o.posted_at')
            ->select('e.id', 'e.account_id', 'e.location_id', 'e.currency', 'e.direction', 'e.amount', 'o.effective_date')
            ->selectRaw("$category AS category, CASE WHEN o.reversal_of_id IS NULL THEN 0 ELSE 1 END AS is_reversal");
    }

    public function daily(int $account, string $date): Builder
    {
        return DB::query()->fromSub($this->entries(), 'events')->where('account_id', $account)->where('effective_date', $date)
            ->selectRaw('currency,category,direction,SUM(amount) AS amount,COUNT(*) AS entry_count,SUM(is_reversal) AS reversal_count')
            ->groupBy('currency', 'category', 'direction')->orderBy('currency')->orderBy('category')->orderBy('direction');
    }
}
