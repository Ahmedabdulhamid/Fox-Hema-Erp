<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Jobs\ProjectCashDay;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CashProjectionRecovery extends SalesProjectionRecovery
{
    public function __construct(string $domain)
    {
        $projection = CashProjection::forDomain($domain);
        $this->domain = $domain;
        $this->scopeColumn = $projection->scopeColumn();
    }

    public function scan(int $limit = 100): void
    {
        $limit = max(1, min(500, $limit));
        foreach ($this->queries() as $source => $query) {
            DB::transaction(function () use ($source, $query, $limit): void {
                $table = 'reporting_'.$this->domain.'_checkpoints';
                DB::table($table)->insertOrIgnore(['source' => $source, 'last_id' => 0]);
                $checkpoint = DB::table($table)->where('source', $source)->lockForUpdate()->first();
                $rows = DB::query()->fromSub($query, 'source')->where('id', '>', $checkpoint->last_id)->orderBy('id')->limit($limit)->get();
                foreach ($rows->filter(fn ($row): bool => $row->report_date !== null)->unique(fn ($row): string => $row->scope_id.':'.$row->report_date) as $row) {
                    CashProjection::forDomain($this->domain)->requestIfChanged((int) $row->scope_id, substr($row->report_date, 0, 10));
                }
                DB::table($table)->where('source', $source)->update(['last_id' => $rows->last()?->id ?? 0, 'scanned_at' => now()]);
            }, 3);
        }
    }

    /** @return array<string,Builder> */
    private function queries(): array
    {
        $partitions = DB::table('reporting_'.$this->domain.'_partitions')->selectRaw('id,'.$this->scopeColumn.' AS scope_id,report_date');
        if ($this->domain === 'treasury') {
            return ['entries' => DB::table('treasury_entries as e')->join('treasury_operations as o', 'o.id', '=', 'e.operation_id')->selectRaw('e.id,e.account_id AS scope_id,o.effective_date AS report_date'), 'partitions' => $partitions];
        }
        $returns = app(PosReportingEvidence::class)->returns();

        return [
            'transactions' => DB::table('pos_transactions')->selectRaw('id,location_id AS scope_id,DATE(COALESCE(server_received_at,created_at)) AS report_date'),
            'sales' => DB::table('sales_invoices as i')->join('pos_transactions as t', 't.sales_invoice_id', '=', 'i.id')->selectRaw('i.id,t.location_id AS scope_id,DATE(i.posted_at) AS report_date'),
            'returns' => (clone $returns)->selectRaw('r.id,s.location_id AS scope_id,DATE(r.posted_at) AS report_date'),
            'credits' => $returns->join('sales_credit_notes as c', 'c.sales_return_id', '=', 'r.id')->selectRaw('c.id,s.location_id AS scope_id,DATE(c.posted_at) AS report_date'),
            'openings' => DB::table('pos_sessions')->selectRaw('id,location_id AS scope_id,DATE(opened_at) AS report_date'),
            'closings' => DB::table('pos_sessions')->selectRaw('id,location_id AS scope_id,DATE(closed_at) AS report_date'),
            'refunds' => DB::table('payment_refunds as r')->join('pos_sessions as s', 's.id', '=', 'r.pos_session_id')->selectRaw('r.id,s.location_id AS scope_id,DATE(r.posted_at) AS report_date'),
            'refund_recoveries' => DB::table('payment_refunds as r')->join('pos_sessions as s', 's.id', '=', 'r.pos_session_id')->join('treasury_operations as o', 'o.id', '=', 'r.recovery_operation_id')->selectRaw('r.id,s.location_id AS scope_id,DATE(o.posted_at) AS report_date'),
            'partitions' => $partitions,
        ];
    }

    protected function dispatchProjection(object $partition): void
    {
        ProjectCashDay::dispatch($this->domain, (int) $partition->{$this->scopeColumn}, $partition->report_date)->afterCommit();
    }
}
