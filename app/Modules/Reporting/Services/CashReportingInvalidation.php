<?php

namespace App\Modules\Reporting\Services;

use App\Filament\ReadModelCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CashReportingInvalidation
{
    public static function currentEpoch(string $domain, int $scope): string
    {
        return 'reporting-'.$domain.'-current:'.$scope;
    }

    public function changed(Model $model): void
    {
        if ($model->getConnection() !== DB::connection()) {
            return;
        }
        $table = $model->getTable();
        $id = (int) $model->getKey();
        if ($table === 'treasury_accounts') {
            $this->current('treasury', $id);

            return;
        }
        if ($table === 'treasury_operations') {
            $this->operation($id);

            return;
        }
        if ($table === 'treasury_entries') {
            $this->operation((int) $model->operation_id);

            return;
        }
        if ($table === 'payment_refunds') {
            foreach (array_filter([$model->treasury_operation_id, $model->recovery_operation_id, $model->getOriginal('treasury_operation_id'), $model->getOriginal('recovery_operation_id')]) as $operation) {
                $this->operation((int) $operation);
            }
        }
        if ($table === 'payments') {
            foreach (DB::table('treasury_operations')->where('payment_id', $id)->pluck('id') as $operation) {
                $this->operation((int) $operation);
            }
        }
        if ($table === 'pos_devices') {
            $this->current('pos', (int) $model->location_id);

            return;
        }
        if ($table === 'pos_sessions') {
            $this->session($id);

            return;
        }
        if (in_array($table, ['pos_transactions', 'payments', 'payment_refunds', 'pos_cash_movements', 'pos_session_tenders'], true)) {
            foreach (array_unique(array_filter([$model->pos_session_id, $model->getOriginal('pos_session_id')])) as $session) {
                $this->session((int) $session);
            }
        }
        $field = match ($table) {
            'sales_invoices' => 'sales_invoice_id','sales_returns' => 'sales_return_id',default => null
        };
        if ($field !== null) {
            foreach (DB::table('pos_transactions')->where(fn ($query) => $query->where($field, $id)->when($model->sales_order_id !== null, fn ($query) => $query->orWhere('sales_order_id', $model->sales_order_id)))->distinct()->pluck('pos_session_id') as $session) {
                $this->session((int) $session);
            }
        }
        if ($table === 'sales_credit_notes') {
            $order = DB::table('sales_returns')->where('id', $model->sales_return_id)->value('sales_order_id');
            foreach (DB::table('pos_transactions')->where(fn ($query) => $query->where('sales_return_id', $model->sales_return_id)->when($order !== null, fn ($query) => $query->orWhere('sales_order_id', $order)))->distinct()->pluck('pos_session_id') as $session) {
                $this->session((int) $session);
            }
        }
    }

    private function current(string $domain, int $scope): void
    {
        if ($scope > 0 && Schema::hasTable('reporting_'.$domain.'_partitions')) {
            app(ReadModelCache::class)->invalidate(self::currentEpoch($domain, $scope));
        }
    }

    private function operation(int $id): void
    {
        if (! Schema::hasTable('reporting_treasury_partitions')) {
            return;
        }
        $operation = DB::table('treasury_operations')->where('id', $id)->first(['effective_date']);
        if ($operation === null) {
            return;
        }
        foreach (DB::table('treasury_entries')->where('operation_id', $id)->distinct()->pluck('account_id') as $account) {
            $this->current('treasury', (int) $account);
            app(TreasuryProjection::class)->request((int) $account, $operation->effective_date);
        }
    }

    private function session(int $id): void
    {
        if (! Schema::hasTable('reporting_pos_partitions')) {
            return;
        }
        $session = DB::table('pos_sessions')->where('id', $id)->first(['location_id', 'opened_at', 'closed_at']);
        if ($session === null) {
            return;
        }
        $this->current('pos', (int) $session->location_id);
        $dates = array_filter([$session->opened_at, $session->closed_at]);
        $transactions = DB::table('pos_transactions as t')->where('t.pos_session_id', $id);
        $queries = [
            (clone $transactions)->selectRaw('DATE(COALESCE(t.server_received_at,t.created_at)) AS day'),
            (clone $transactions)->join('sales_invoices as i', 'i.id', '=', 't.sales_invoice_id')->selectRaw('DATE(i.posted_at) AS day'),
            (clone $transactions)->join('sales_returns as r', 'r.id', '=', 't.sales_return_id')->selectRaw('DATE(r.posted_at) AS day'),
            (clone $transactions)->join('sales_credit_notes as c', 'c.sales_return_id', '=', 't.sales_return_id')->selectRaw('DATE(c.posted_at) AS day'),
            (clone $transactions)->join('sales_returns as r', 'r.sales_order_id', '=', 't.sales_order_id')->selectRaw('DATE(r.posted_at) AS day'),
            (clone $transactions)->join('sales_returns as r', 'r.sales_order_id', '=', 't.sales_order_id')->join('sales_credit_notes as c', 'c.sales_return_id', '=', 'r.id')->selectRaw('DATE(c.posted_at) AS day'),
            DB::table('payment_refunds')->where('pos_session_id', $id)->selectRaw('DATE(posted_at) AS day'),
            DB::table('payment_refunds as r')->join('treasury_operations as o', 'o.id', '=', 'r.recovery_operation_id')->where('r.pos_session_id', $id)->selectRaw('DATE(o.posted_at) AS day'),
        ];
        $query = array_shift($queries);
        foreach ($queries as $part) {
            $query->union($part);
        }
        foreach ($query->pluck('day') as $date) {
            if ($date !== null) {
                $dates[] = $date;
            }
        }
        foreach (array_unique(array_map(fn ($date): string => substr((string) $date, 0, 10), $dates)) as $date) {
            app(PosProjection::class)->request((int) $session->location_id, $date);
        }
    }
}
