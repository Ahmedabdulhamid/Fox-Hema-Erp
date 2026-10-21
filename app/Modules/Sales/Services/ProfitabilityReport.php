<?php

namespace App\Modules\Sales\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\InventoryCosting\Services\ProfitabilityCalculator;
use App\Modules\Sales\Models\SalesDeliveryItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProfitabilityReport
{
    /**
     * Delivery-basis operational gross profit. Physical returns reverse the agreed revenue and
     * original COGS once. Credits adjust only the difference from that revenue reversal.
     * Return losses and standard-cost variances are separate from gross profit.
     */
    public function canView(?User $actor, ?int $locationId = null): bool
    {
        $access = app(SalesAccess::class);

        return app(SalesProfitability::class)->canView($actor, $locationId)
            && $access->allows($actor, SalesPermissions::DELIVERIES_VIEW, $locationId)
            && $access->allows($actor, SalesPermissions::RETURNS_VIEW, $locationId)
            && $access->allows($actor, SalesPermissions::CREDITS_VIEW, $locationId);
    }

    public function events(User $actor, string $from, string $until, ?int $locationId = null, ?int $orderId = null): Builder
    {
        abort_unless($this->canView($actor, $locationId), 403);
        Validator::make(['from' => $from, 'until' => $until], [
            'from' => ['required', 'date_format:Y-m-d'], 'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();
        abort_if(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($until)) > 366, 422, __('Choose a date range of at most 366 days.'));
        $start = $from.' 00:00:00';
        $end = CarbonImmutable::parse($until)->addDay()->format('Y-m-d').' 00:00:00';
        $locationId = $actor->isGlobalSuperAdmin() ? $locationId : (int) getPermissionsTeamId();
        $deliveries = $this->deliveries($locationId, $orderId);
        $returns = $this->returns($deliveries, $start, $end, $locationId, $orderId);
        $sales = DB::query()->fromSub(clone $deliveries, 'd')->where('d.posted_at', '>=', $start)->where('d.posted_at', '<', $end)
            ->selectRaw('d.order_id, d.location_id, d.variant_id, d.posted_at, d.net AS revenue, d.cost, d.missing, 0 AS return_loss, 0 AS return_variance');
        $refunds = DB::query()->fromSub(clone $returns, 'r')->where('r.posted_at', '>=', $start)->where('r.posted_at', '<', $end)
            ->selectRaw('r.order_id, r.location_id, r.variant_id, r.posted_at, -r.net AS revenue, -r.cost AS cost, r.missing, r.return_loss, r.return_variance');
        $previousCredits = "(SELECT COALESCE(SUM(pc.quantity), 0) FROM sales_credit_note_items pc JOIN sales_credit_notes pn ON pn.id = pc.sales_credit_note_id JOIN document_numbers pd ON pd.document_uuid = pn.uuid AND pd.document_type = 'CN' WHERE pc.sales_return_item_id = ci.sales_return_item_id AND pn.status = 'posted' AND pd.id < dn.id)";
        $creditedReturn = $this->allocation('r.net', 'r.quantity', $previousCredits, 'ci.quantity');
        $credits = DB::table('sales_credit_note_items as ci')->join('sales_credit_notes as cn', 'cn.id', '=', 'ci.sales_credit_note_id')
            ->join('document_numbers as dn', fn ($join) => $join->on('dn.document_uuid', '=', 'cn.uuid')->where('dn.document_type', 'CN'))
            ->joinSub($returns, 'r', 'r.id', '=', 'ci.sales_return_item_id')
            ->where('cn.status', 'posted')->whereNotNull('cn.posting_payload_hash')->whereNotNull('cn.posted_by')
            ->whereColumn('cn.location_id', 'r.location_id')->where('cn.posted_at', '>=', $start)->where('cn.posted_at', '<', $end)
            ->selectRaw("r.order_id, r.location_id, r.variant_id, cn.posted_at, ($creditedReturn - (ci.subtotal - ci.discount_amount)) AS revenue, 0 AS cost, r.missing, 0 AS return_loss, 0 AS return_variance");

        return DB::query()->fromSub($sales->unionAll($refunds)->unionAll($credits), 'events');
    }

    public function summary(Builder $events): array
    {
        $row = (clone $events)->selectRaw('COALESCE(SUM(revenue), 0) AS revenue, COALESCE(SUM(cost), 0) AS cost, COALESCE(SUM(missing), 0) AS missing, COALESCE(SUM(return_loss), 0) AS return_loss, COALESCE(SUM(return_variance), 0) AS return_variance')->first();

        return app(ProfitabilityCalculator::class)->calculate((string) $row->revenue, $row->missing > 0 ? null : (string) $row->cost)
            + ['missing' => (int) $row->missing, 'return_loss' => (string) $row->return_loss, 'return_variance' => (string) $row->return_variance];
    }

    public function grouped(Builder $events, string $group): Builder
    {
        $column = match ($group) {
            'product' => 'p.id', 'variant' => 'v.id', 'location' => 'l.id', default => 'o.id',
        };
        $label = match ($group) {
            'product' => 'p.name', 'variant' => 'v.sku', 'location' => 'l.name', default => 'o.order_number',
        };

        return (clone $events)->join('sales_orders as o', 'o.id', '=', 'events.order_id')
            ->join('locations as l', 'l.id', '=', 'events.location_id')->join('product_variants as v', 'v.id', '=', 'events.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->selectRaw("$column AS id, $label AS label, SUM(events.revenue) AS revenue, CASE WHEN SUM(events.missing) > 0 THEN NULL ELSE SUM(events.cost) END AS cost, SUM(events.missing) AS missing")
            ->groupBy($column, $label)->orderBy($column);
    }

    private function deliveries(?int $locationId, ?int $orderId): Builder
    {
        $previous = "(SELECT COALESCE(SUM(pi.quantity), 0) FROM sales_delivery_items pi JOIN sales_deliveries pd ON pd.id = pi.sales_delivery_id WHERE pi.sales_order_item_id = i.sales_order_item_id AND pd.status = 'posted' AND pi.stock_movement_id < i.stock_movement_id)";
        $net = $this->allocation('(oi.subtotal - oi.discount_amount)', 'oi.quantity', $previous, 'i.quantity');
        $valid = "e.id IS NOT NULL AND e.entry_type = 'issue' AND e.method IN ('fifo', 'avco', 'standard') AND e.quantity = -i.quantity AND e.total_cost <= 0 AND m.quantity = -i.quantity AND m.type = 'sale' AND m.reference_id = i.id AND m.variant_id = oi.variant_id AND m.location_id = d.location_id AND m.posting_payload_hash IS NOT NULL AND (e.method <> 'standard' OR e.standard_cost_revision_id IS NOT NULL) AND (e.method <> 'fifo' OR ((SELECT COALESCE(SUM(fc.quantity), 0) FROM fifo_layer_consumptions fc WHERE fc.cost_entry_id = e.id) = i.quantity AND (SELECT COALESCE(SUM(fc.total_cost), 0) FROM fifo_layer_consumptions fc WHERE fc.cost_entry_id = e.id) = -e.total_cost))";

        $valid .= ' AND d.posted_by IS NOT NULL AND d.posting_payload_hash IS NOT NULL AND d.posting_evidence_hash IS NOT NULL';

        return DB::table('sales_delivery_items as i')->join('sales_deliveries as d', 'd.id', '=', 'i.sales_delivery_id')
            ->join('sales_order_items as oi', 'oi.id', '=', 'i.sales_order_item_id')->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')
            ->join('locations as l', 'l.id', '=', 'd.location_id')->where('l.type', 'branch')
            ->leftJoin('stock_movements as m', fn ($join) => $join->on('m.id', '=', 'i.stock_movement_id')->where('m.reference_type', (new SalesDeliveryItem)->getMorphClass()))
            ->leftJoin('inventory_cost_entries as e', 'e.stock_movement_id', '=', 'm.id')
            ->where('d.status', 'posted')->whereNotNull('d.posted_at')
            ->whereColumn('d.location_id', 'o.location_id')->whereColumn('d.sales_order_id', 'o.id')->whereColumn('oi.sales_order_id', 'o.id')
            ->when($locationId !== null, fn ($q) => $q->where('d.location_id', $locationId))
            ->when($orderId !== null, fn ($q) => $q->where('o.id', $orderId))
            ->selectRaw("i.id, i.quantity, o.id AS order_id, d.location_id, oi.variant_id, d.posted_at, e.id AS cost_entry_id, $net AS net, CASE WHEN $valid THEN -e.total_cost ELSE NULL END AS cost, CASE WHEN $valid THEN 0 ELSE 1 END AS missing");
    }

    private function returns(Builder $deliveries, string $start, string $end, ?int $locationId, ?int $orderId): Builder
    {
        $candidates = DB::table('sales_return_items as target')->join('sales_returns as document', 'document.id', '=', 'target.sales_return_id')
            ->where('document.status', 'posted')
            ->when($locationId !== null, fn ($q) => $q->where('document.location_id', $locationId))
            ->when($orderId !== null, fn ($q) => $q->where('document.sales_order_id', $orderId))
            ->where('document.posted_at', '>=', $start)->where('document.posted_at', '<', $end)->select('target.id');
        $adjusted = DB::table('sales_credit_note_items as adjustment')->join('sales_credit_notes as note', 'note.id', '=', 'adjustment.sales_credit_note_id')
            ->join('sales_returns as document', 'document.id', '=', 'note.sales_return_id')->where('note.status', 'posted')
            ->when($locationId !== null, fn ($q) => $q->where('note.location_id', $locationId))
            ->when($orderId !== null, fn ($q) => $q->where('document.sales_order_id', $orderId))
            ->where('note.posted_at', '>=', $start)->where('note.posted_at', '<', $end)->select('adjustment.sales_return_item_id');
        $candidates->union($adjusted);
        $allocations = DB::table('sales_return_cost_allocations as a')->selectRaw('a.sales_return_item_id, a.original_cost_entry_id, a.location_id, a.variant_id, SUM(a.quantity) AS quantity, SUM(a.original_cost) AS cost, SUM(a.loss_value) AS return_loss, SUM(a.return_variance) AS return_variance, MIN(a.id) AS first_id')
            ->whereIn('a.sales_return_item_id', clone $candidates)
            ->groupBy('a.sales_return_item_id', 'a.original_cost_entry_id', 'a.location_id', 'a.variant_id');
        $previous = "(SELECT COALESCE(SUM(pr.quantity), 0) FROM sales_return_items pr JOIN sales_returns ph ON ph.id = pr.sales_return_id WHERE pr.sales_delivery_item_id = ri.sales_delivery_item_id AND ph.status = 'posted' AND (SELECT MIN(pa.id) FROM sales_return_cost_allocations pa WHERE pa.sales_return_item_id = pr.id) < a.first_id)";
        $net = $this->allocation('d.net', 'd.quantity', $previous, 'ri.quantity');
        $valid = 'a.quantity = ri.quantity AND d.missing = 0 AND rh.posted_by IS NOT NULL AND rh.posting_payload_hash IS NOT NULL AND rh.posting_evidence_hash IS NOT NULL';

        return DB::table('sales_return_items as ri')->join('sales_returns as rh', 'rh.id', '=', 'ri.sales_return_id')
            ->whereIn('ri.id', $candidates)
            ->joinSub($deliveries, 'd', 'd.id', '=', 'ri.sales_delivery_item_id')
            ->leftJoinSub($allocations, 'a', fn ($join) => $join->on('a.sales_return_item_id', '=', 'ri.id')->on('a.original_cost_entry_id', '=', 'd.cost_entry_id')
                ->on('a.location_id', '=', 'd.location_id')->on('a.variant_id', '=', 'd.variant_id'))
            ->where('rh.status', 'posted')->whereNotNull('rh.posted_at')
            ->whereColumn('rh.location_id', 'd.location_id')->whereColumn('rh.sales_order_id', 'd.order_id')
            ->selectRaw("ri.id, ri.quantity, d.order_id, d.location_id, d.variant_id, rh.posted_at, $net AS net, CASE WHEN $valid THEN a.cost ELSE NULL END AS cost, CASE WHEN $valid THEN 0 ELSE 1 END AS missing, a.return_loss, a.return_variance");
    }

    /** Cumulative allocation telescopes to the original net amount, including four-decimal residuals. */
    private function allocation(string $amount, string $total, string $previous, string $quantity): string
    {
        return "(ROUND($amount * ($previous + $quantity) / NULLIF($total, 0), 4) - ROUND($amount * $previous / NULLIF($total, 0), 4))";
    }
}
