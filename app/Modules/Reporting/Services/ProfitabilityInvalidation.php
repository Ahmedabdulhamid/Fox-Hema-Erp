<?php

namespace App\Modules\Reporting\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProfitabilityInvalidation
{
    public function posted(string $table, int $id): void
    {
        if (! Schema::hasTable('reporting_profitability_partitions')) {
            return;
        }
        $document = DB::table($table)->where('id', $id)->where('status', 'posted')->whereNotNull('posted_at')
            ->selectRaw('location_id, DATE(posted_at) AS report_date')->first();
        if ($document !== null) {
            app(ProfitabilityProjection::class)->request((int) $document->location_id, $document->report_date);
        }
    }

    public function evidence(Model $model): void
    {
        if (! Schema::hasTable('reporting_profitability_partitions')) {
            return;
        }
        if ($model->getTable() === 'stock_movements') {
            $movementIds = [$model->getKey()];
        } elseif ($model->getTable() === 'inventory_cost_entries') {
            $movementIds = [$model->stock_movement_id, $model->getOriginal('stock_movement_id')];
        } else {
            $field = $model->getTable() === 'fifo_layer_consumptions' ? 'cost_entry_id' : 'original_cost_entry_id';
            $movementIds = DB::table('inventory_cost_entries')->whereIn('id', array_filter([$model->{$field}, $model->getOriginal($field)]))
                ->pluck('stock_movement_id')->all();
        }
        $originalEntries = DB::table('sales_return_cost_allocations')->whereIn('stock_movement_id', array_filter($movementIds))->select('original_cost_entry_id');
        $originalMovements = DB::table('inventory_cost_entries')->whereIn('id', $originalEntries)->pluck('stock_movement_id')->all();
        $items = DB::table('sales_delivery_items')->whereIn('stock_movement_id', array_unique([...$movementIds, ...$originalMovements]))->pluck('id')->all();
        if ($items === []) {
            return;
        }
        $deliveries = DB::table('sales_delivery_items as i')->join('sales_deliveries as d', 'd.id', '=', 'i.sales_delivery_id')
            ->whereIn('i.id', $items)->where('d.status', 'posted')->selectRaw('d.location_id, DATE(d.posted_at) AS report_date');
        $returns = DB::table('sales_return_items as i')->join('sales_returns as d', 'd.id', '=', 'i.sales_return_id')
            ->whereIn('i.sales_delivery_item_id', $items)->where('d.status', 'posted')->selectRaw('d.location_id, DATE(d.posted_at) AS report_date');
        $credits = DB::table('sales_credit_note_items as ci')->join('sales_credit_notes as d', 'd.id', '=', 'ci.sales_credit_note_id')
            ->join('sales_return_items as ri', 'ri.id', '=', 'ci.sales_return_item_id')->whereIn('ri.sales_delivery_item_id', $items)
            ->where('d.status', 'posted')->selectRaw('d.location_id, DATE(d.posted_at) AS report_date');
        foreach ($deliveries->union($returns)->union($credits)->get() as $partition) {
            if ($partition->report_date !== null) {
                app(ProfitabilityProjection::class)->request((int) $partition->location_id, $partition->report_date);
            }
        }
    }
}
