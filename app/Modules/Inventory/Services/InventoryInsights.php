<?php

namespace App\Modules\Inventory\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Sales\Models\SalesDeliveryItem;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryInsights
{
    /** Posted fulfillment only; each return is counted once regardless of its cost slices. */
    public function sales(User $actor): QueryBuilder
    {
        $locations = app(InventoryAccess::class)->locations($actor, InventoryPermissions::BALANCES)->select('id');
        $deliveries = DB::table('sales_delivery_items as di')
            ->join('sales_deliveries as d', 'd.id', '=', 'di.sales_delivery_id')
            ->join('sales_order_items as oi', 'oi.id', '=', 'di.sales_order_item_id')
            ->join('stock_movements as sm', 'sm.id', '=', 'di.stock_movement_id')
            ->whereIn('d.location_id', clone $locations)->where('d.status', 'posted')->whereNotNull('d.posted_at')
            ->whereNotNull('d.posted_by')->whereNotNull('d.posting_evidence_hash')->whereNull('d.cancelled_at')
            ->whereNotNull('d.posting_payload_hash')->whereNotNull('sm.posting_payload_hash')
            ->where('sm.reference_type', (new SalesDeliveryItem)->getMorphClass())
            ->whereColumn('sm.reference_id', 'di.id')->whereColumn('sm.variant_id', 'oi.variant_id')
            ->whereColumn('sm.location_id', 'd.location_id')->whereColumn('oi.sales_order_id', 'd.sales_order_id')
            ->where('sm.type', 'sale')->whereRaw('sm.quantity = -di.quantity')
            ->selectRaw('oi.variant_id, d.location_id, d.posted_at AS occurred_at, di.quantity AS units, 1 AS is_sale');
        $returns = DB::table('sales_return_items as ri')->join('sales_returns as r', 'r.id', '=', 'ri.sales_return_id')
            ->join('sales_delivery_items as di', 'di.id', '=', 'ri.sales_delivery_item_id')
            ->join('sales_deliveries as d', 'd.id', '=', 'di.sales_delivery_id')
            ->join('sales_order_items as oi', 'oi.id', '=', 'di.sales_order_item_id')
            ->whereIn('r.location_id', clone $locations)->where('r.status', 'posted')->whereNotNull('r.posted_at')
            ->whereNotNull('r.posted_by')->whereNotNull('r.posting_evidence_hash')
            ->whereIn('di.id', (clone $deliveries)->select('di.id'))
            ->whereNotNull('r.posting_payload_hash')->where('d.status', 'posted')->whereNotNull('di.stock_movement_id')
            ->whereColumn('r.location_id', 'd.location_id')->whereColumn('r.sales_order_id', 'd.sales_order_id')
            ->whereColumn('ri.variant_id', 'oi.variant_id')
            ->selectRaw('ri.variant_id, r.location_id, r.posted_at AS occurred_at, -ri.quantity AS units, 0 AS is_sale');

        return DB::query()->fromSub($deliveries->unionAll($returns), 'fulfillment')->where('occurred_at', '<=', now());
    }

    /** @param array<string, mixed> $filters */
    public function query(User $actor, array $filters = []): Builder
    {
        $access = app(InventoryAccess::class);
        $access->authorize($actor, InventoryPermissions::BALANCES);
        $locations = $access->locations($actor, InventoryPermissions::BALANCES)->select(['id', 'name', 'is_active']);
        $history = StockMovement::query()->whereIn('location_id', (clone $locations)->select('id'))
            ->when(filled($filters['location'] ?? null), fn (Builder $q) => $q->where('location_id', $filters['location']))
            ->when(filled($filters['product'] ?? null), fn (Builder $q) => $q->whereIn('variant_id', ProductVariant::where('product_id', $filters['product'])->select('id')))
            ->where('quantity', '>', 0)->where('created_at', '<=', now())
            ->selectRaw('variant_id, location_id, MIN(created_at) AS first_received_at')->groupBy('variant_id', 'location_id');
        $from = CarbonImmutable::parse($filters['from'] ?? today()->subDays(29)->toDateString())->startOfDay();
        $until = CarbonImmutable::parse($filters['until'] ?? today()->toDateString())->addDay()->startOfDay();
        $events = Schema::hasTable('sales_delivery_items') ? $this->sales($actor) : DB::query()->selectRaw('NULL AS variant_id, NULL AS location_id, NULL AS occurred_at, 0 AS units, 0 AS is_sale')->whereRaw('1 = 0');
        $events->when(filled($filters['location'] ?? null), fn (QueryBuilder $q) => $q->where('location_id', $filters['location']))
            ->when(filled($filters['product'] ?? null), fn (QueryBuilder $q) => $q->whereIn('variant_id', ProductVariant::where('product_id', $filters['product'])->select('id')));
        $sales = DB::query()->fromSub($events, 'events')->selectRaw('variant_id, location_id, MAX(CASE WHEN is_sale = 1 THEN occurred_at END) AS last_sale_at, SUM(CASE WHEN occurred_at >= ? AND occurred_at < ? THEN units ELSE 0 END) AS net_sold', [$from, $until])->groupBy('variant_id', 'location_id');
        $base = ProductVariant::query()->crossJoinSub($locations, 'inventory_location')
            ->leftJoin('stock_balances as balance', fn (JoinClause $join) => $join->on('balance.variant_id', '=', 'product_variants.id')->on('balance.location_id', '=', 'inventory_location.id'))
            ->leftJoin('inventory_stock_policies as policy', fn (JoinClause $join) => $join->on('policy.variant_id', '=', 'product_variants.id')->on('policy.location_id', '=', 'inventory_location.id'))
            ->leftJoinSub($history, 'history', fn (JoinClause $join) => $join->on('history.variant_id', '=', 'product_variants.id')->on('history.location_id', '=', 'inventory_location.id'))
            ->leftJoinSub($sales, 'sales', fn (JoinClause $join) => $join->on('sales.variant_id', '=', 'product_variants.id')->on('sales.location_id', '=', 'inventory_location.id'))
            ->select('product_variants.id', 'product_variants.uuid', 'product_variants.product_id', 'product_variants.color_id', 'product_variants.size_id', 'product_variants.sku', 'product_variants.barcode', 'product_variants.sale_price', 'product_variants.is_active', 'product_variants.unallocated_product_id', 'inventory_location.id as inventory_location_id', 'inventory_location.name as location_name', 'inventory_location.is_active as location_active', 'balance.id as balance_id', 'balance.quantity_on_hand', 'balance.quantity_reserved', 'balance.quantity_available', 'balance.created_at as balance_created_at', 'policy.reorder_threshold', 'history.first_received_at', 'sales.last_sale_at')
            ->selectRaw('COALESCE(policy.dead_stock_days, 90) AS dead_stock_days, COALESCE(sales.net_sold, 0) AS net_sold')
            ->when(filled($filters['location'] ?? null), fn (Builder $q) => $q->where('inventory_location.id', $filters['location']))
            ->when(filled($filters['product'] ?? null), fn (Builder $q) => $q->where('product_variants.product_id', $filters['product']))
            ->when(filled($filters['variant'] ?? null), fn (Builder $q) => $q->where('product_variants.id', $filters['variant']))
            ->when(filled($filters['brand'] ?? null), fn (Builder $q) => $q->whereHas('product', fn (Builder $p) => $p->where('brand_id', $filters['brand'])))
            ->when(filled($filters['category'] ?? null), fn (Builder $q) => $q->whereHas('product', fn (Builder $p) => $p->where('category_id', $filters['category'])))
            ->when(filled($filters['search'] ?? null), fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('product_variants.sku', 'like', '%'.$filters['search'].'%')->orWhere('product_variants.barcode', 'like', '%'.$filters['search'].'%')->orWhereHas('product', fn (Builder $p) => $p->where('name', 'like', '%'.$filters['search'].'%'))));

        if ($access->allows($actor, InventoryPermissions::COST) && Schema::hasColumn('inventory_cost_states', 'location_id')) {
            $base->leftJoin('inventory_cost_states as valuation', fn (JoinClause $join) => $join->on('valuation.variant_id', '=', 'product_variants.id')->on('valuation.location_id', '=', 'inventory_location.id'))
                ->selectRaw('CASE WHEN valuation.on_hand_qty = balance.quantity_on_hand THEN valuation.total_value ELSE NULL END AS reliable_stock_value');
        }

        return ProductVariant::query()->fromSub($base->toBase(), 'product_variants')->select('product_variants.*')
            ->with(['product.brand', 'product.category', 'product.images', 'images', 'size', 'color']);
    }

    public function status(Builder $query, string $status): Builder
    {
        return $query->whereRaw($this->statusCondition($status), $status === 'dead' ? [now(), now(), now()] : []);
    }

    /** Current sellable balances remain independent of the sales reporting period. */
    public function intelligence(Builder $query, string $availability, string $performance, int $inactiveDays = 90): Builder
    {
        if ($availability !== '' || $performance === 'slow') {
            $query->whereNull('unallocated_product_id')->whereNotNull('balance_id');
        }
        match ($availability) {
            'in_stock', 'low' => $query->where('quantity_available', '>', 0),
            'out' => $query->where('quantity_available', '=', 0),
            default => null,
        };
        if ($performance === 'slow') {
            $cutoff = now()->subDays(in_array($inactiveDays, [30, 60, 90, 180], true) ? $inactiveDays : 90);
            $query->where('quantity_available', '>', 0)
                ->where('balance_created_at', '<=', $cutoff)
                ->whereRaw('COALESCE(first_received_at, balance_created_at) <= ?', [$cutoff])
                ->where(fn (Builder $sales) => $sales->whereNull('last_sale_at')->orWhere('last_sale_at', '<=', $cutoff));
        }

        return $query;
    }

    private function statusCondition(string $status): string
    {
        return match ($status) {
            'out' => 'balance_id IS NOT NULL AND quantity_available = 0',
            'low' => 'quantity_available > 0 AND reorder_threshold IS NOT NULL AND quantity_available <= reorder_threshold',
            'best' => 'net_sold > 0',
            'dead' => 'quantity_available > 0 AND first_received_at IS NOT NULL AND balance_created_at IS NOT NULL AND '.$this->deadCondition(),
            'missing' => 'balance_id IS NULL',
            'unknown' => 'quantity_available > 0 AND first_received_at IS NULL',
            'reserved' => 'quantity_reserved > 0', 'available' => 'quantity_available > 0', 'on_hand' => 'quantity_on_hand > 0',
            default => '1 = 1',
        };
    }

    private function deadCondition(): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return 'julianday(?) - julianday(first_received_at) >= dead_stock_days AND julianday(?) - julianday(balance_created_at) >= dead_stock_days AND (last_sale_at IS NULL OR julianday(?) - julianday(last_sale_at) >= dead_stock_days)';
        }

        return 'TIMESTAMPDIFF(DAY, first_received_at, ?) >= dead_stock_days AND TIMESTAMPDIFF(DAY, balance_created_at, ?) >= dead_stock_days AND (last_sale_at IS NULL OR TIMESTAMPDIFF(DAY, last_sale_at, ?) >= dead_stock_days)';
    }

    /** @return array<string, int|string> */
    public function summary(Builder $query): array
    {
        $aggregate = (clone $query)->reorder()->toBase()->select([])->selectRaw('COUNT(DISTINCT product_id) AS products, COUNT(DISTINCT id) AS variants, COALESCE(SUM(quantity_on_hand), 0) AS on_hand, COALESCE(SUM(quantity_reserved), 0) AS reserved, COALESCE(SUM(quantity_available), 0) AS available, COALESCE(SUM(CASE WHEN unallocated_product_id IS NOT NULL THEN quantity_on_hand ELSE 0 END), 0) AS unallocated');
        foreach (['out', 'low', 'best', 'dead', 'missing', 'unknown'] as $status) {
            $aggregate->selectRaw('COALESCE(SUM(CASE WHEN '.$this->statusCondition($status).' THEN 1 ELSE 0 END), 0) AS '.DB::connection()->getQueryGrammar()->wrap($status), $status === 'dead' ? [now(), now(), now()] : []);
        }

        return (array) $aggregate->first();
    }

    /** @return array<string, mixed> */
    public function matrix(User $actor, int $productId, int $locationId, string $metric, int $page = 1): array
    {
        app(InventoryAccess::class)->authorize($actor, InventoryPermissions::BALANCES, $locationId);
        $field = match ($metric) {
            'reserved' => 'quantity_reserved', 'on_hand' => 'quantity_on_hand', default => 'quantity_available',
        };
        $query = $this->query($actor, ['product' => $productId, 'location' => $locationId]);
        $unallocated = (clone $query)->whereNotNull('unallocated_product_id')->get();
        $query->whereNull('unallocated_product_id');
        $colors = (clone $query)->toBase()->select('color_id')->groupBy('color_id')->orderBy('color_id')->paginate(12, ['*'], 'colorsPage', $page);
        $ids = $colors->pluck('color_id')->filter()->all();
        $hasNull = $colors->contains(fn ($row): bool => $row->color_id === null);
        $variants = (clone $query)->where(fn (Builder $q) => $q->whereIn('color_id', $ids)->when($hasNull, fn (Builder $q) => $q->orWhereNull('color_id')))->orderBy('color_id')->orderBy('id')->get();
        $sizes = Size::whereIn('id', (clone $query)->select('size_id'))->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
        if ((clone $query)->whereNull('size_id')->exists()) {
            $sizes = [0 => __('No size')] + $sizes;
        }
        $rows = [];
        foreach ($variants->groupBy(fn (ProductVariant $v): int => $v->color_id ?? 0) as $group) {
            $total = BigDecimal::zero();
            $cells = [];
            foreach ($group as $variant) {
                $total = $total->plus($variant->$field ?? '0');
                $cells[$variant->size_id ?? 0][] = $variant;
            }
            $rows[] = ['label' => $group->first()->color?->name ?? __('No color'), 'hex' => $group->first()->color?->hex_code, 'cells' => $cells, 'total' => (string) $total];
        }
        $totals = (clone $query)->toBase()->select('size_id')->selectRaw("SUM($field) AS quantity")->groupBy('size_id')->get();
        $columnTotals = [];
        $grand = BigDecimal::zero();
        foreach ($totals as $total) {
            $columnTotals[$total->size_id ?? 0] = (string) ($total->quantity ?? '0');
            $grand = $grand->plus($total->quantity ?? '0');
        }

        return ['rows' => $rows, 'sizes' => $sizes, 'columns' => $columnTotals, 'total' => (string) $grand, 'colors' => $colors, 'field' => $field, 'unallocated' => $unallocated];
    }

    public function stockValue(User $actor, ProductVariant $variant): ?string
    {
        $locationId = (int) $variant->inventory_location_id;
        if (! app(InventoryAccess::class)->allows($actor, InventoryPermissions::COST, $locationId)
            || ! Schema::hasColumn('inventory_cost_states', 'location_id')) {
            return null;
        }
        $state = DB::table('inventory_cost_states')->where('variant_id', $variant->id)->where('location_id', $locationId)->first();

        return $state !== null && BigDecimal::of((string) $state->on_hand_qty)->isEqualTo($variant->quantity_on_hand ?? '-1') ? (string) $state->total_value : null;
    }
}
