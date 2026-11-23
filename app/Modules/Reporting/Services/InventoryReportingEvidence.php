<?php

namespace App\Modules\Reporting\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class InventoryReportingEvidence
{
    /**
     * Signed quantities and carrying-value deltas are evidence, never recomputed unit prices.
     * A complete ledger must reconcile to the live physical/valuation anchors before history is shown.
     */
    public function closings(int $locationId, string $date): Builder
    {
        $end = CarbonImmutable::parse($date)->addDay()->toDateString().' 00:00:00';
        $consumptions = DB::table('fifo_layer_consumptions')->selectRaw('cost_entry_id, SUM(quantity) AS quantity, SUM(total_cost) AS total_cost')->groupBy('cost_entry_id');
        $valid = "m.posting_payload_hash IS NOT NULL AND m.created_at IS NOT NULL AND e.id IS NOT NULL AND e.quantity = m.quantity
            AND e.method IN ('fifo','avco','standard') AND e.unit_cost >= 0
            AND ((m.quantity > 0 AND e.total_cost >= 0) OR (m.quantity < 0 AND e.total_cost <= 0))
            AND (e.method <> 'standard' OR revision.id IS NOT NULL)
            AND (e.method <> 'fifo' OR (m.quantity > 0 AND fl.id IS NOT NULL AND fl.quantity = e.quantity AND fl.unit_cost = e.unit_cost AND (fl.initial_value IS NULL OR fl.initial_value = e.total_cost))
                OR (m.quantity < 0 AND fc.quantity = -e.quantity AND fc.total_cost = -e.total_cost))";

        $ledger = DB::table('stock_movements as m')->leftJoin('inventory_cost_entries as e', 'e.stock_movement_id', '=', 'm.id')
            ->leftJoin('fifo_layers as fl', 'fl.stock_movement_id', '=', 'm.id')
            ->leftJoin('inventory_standard_cost_revisions as revision', 'revision.id', '=', 'e.standard_cost_revision_id')
            ->leftJoinSub($consumptions, 'fc', 'fc.cost_entry_id', '=', 'e.id')->where('m.location_id', $locationId)
            ->selectRaw('m.variant_id, SUM(m.quantity) AS ledger_quantity, COALESCE(SUM(e.total_cost), 0) AS ledger_value')
            ->selectRaw('SUM(CASE WHEN m.posting_payload_hash IS NULL OR m.created_at IS NULL THEN 1 ELSE 0 END) AS unknown_quantity')
            ->selectRaw("SUM(CASE WHEN ($valid) THEN 0 ELSE 1 END) AS unknown_cost")
            ->selectRaw('SUM(CASE WHEN m.created_at < ? THEN m.quantity ELSE 0 END) AS closing_quantity', [$end])
            ->selectRaw('COALESCE(SUM(CASE WHEN m.created_at < ? THEN e.total_cost ELSE 0 END), 0) AS closing_value', [$end])
            ->groupBy('m.variant_id');

        $variants = DB::table('stock_balances')->where('location_id', $locationId)->select('variant_id')
            ->union(DB::table('stock_movements')->where('location_id', $locationId)->select('variant_id'));
        $quantityKnown = 'b.id IS NOT NULL AND COALESCE(l.unknown_quantity,0) = 0 AND COALESCE(l.ledger_quantity,0) = b.quantity_on_hand';
        if ($date > today()->toDateString()) {
            $quantityKnown = '1 = 0';
        }
        $costKnown = "$quantityKnown AND COALESCE(l.unknown_cost,0) = 0 AND s.id IS NOT NULL
            AND s.on_hand_qty = b.quantity_on_hand AND s.total_value = COALESCE(l.ledger_value,0)
            AND s.on_hand_qty >= 0 AND s.avg_cost >= 0 AND s.total_value >= 0 AND COALESCE(l.closing_value,0) >= 0
            AND (s.on_hand_qty <> 0 OR (s.total_value = 0 AND s.avg_cost = 0))
            AND (COALESCE(l.closing_quantity,0) <> 0 OR COALESCE(l.closing_value,0) = 0)";

        return DB::query()->fromSub($variants, 'v')->leftJoinSub($ledger, 'l', 'l.variant_id', '=', 'v.variant_id')
            ->leftJoin('stock_balances as b', fn ($join) => $join->on('b.variant_id', '=', 'v.variant_id')->where('b.location_id', $locationId))
            ->leftJoin('inventory_cost_states as s', fn ($join) => $join->on('s.variant_id', '=', 'v.variant_id')->where('s.location_id', $locationId))
            ->select('v.variant_id')
            ->selectRaw("CASE WHEN $quantityKnown AND COALESCE(l.closing_quantity,0) >= 0 THEN COALESCE(l.closing_quantity,0) ELSE NULL END AS closing_quantity")
            ->selectRaw("CASE WHEN $costKnown AND COALESCE(l.closing_quantity,0) >= 0 THEN COALESCE(l.closing_value,0) ELSE NULL END AS closing_value");
    }

    public function movements(int $locationId, string $date): Builder
    {
        return DB::table('stock_movements')->where('location_id', $locationId)->where('created_at', '>=', $date.' 00:00:00')
            ->where('created_at', '<', CarbonImmutable::parse($date)->addDay()->toDateString().' 00:00:00')
            ->selectRaw('variant_id, type, COUNT(*) AS movement_count,
                SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) AS received_quantity,
                SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) AS issued_quantity,
                SUM(CASE WHEN reverses_movement_id IS NOT NULL THEN 1 ELSE 0 END) AS reversal_count')
            ->groupBy('variant_id', 'type')->orderBy('variant_id')->orderBy('type');
    }
}
