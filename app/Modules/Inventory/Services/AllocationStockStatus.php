<?php

namespace App\Modules\Inventory\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Models\SizeAllocationItem;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\PurchaseReceiptItem;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class AllocationStockStatus
{
    /** Receipt evidence is inventory data; receipt identities are separately permission checked by the UI. */
    public function receipts(User $actor): QueryBuilder
    {
        $locations = app(InventoryAccess::class)->locations($actor, InventoryPermissions::ALLOCATIONS_VIEW)->select('id');

        return DB::table('purchase_receipt_items as ri')->join('purchase_receipts as r', 'r.id', '=', 'ri.purchase_receipt_id')
            ->join('purchase_orders as po', 'po.id', '=', 'r.purchase_order_id')
            ->join('purchase_order_items as oi', 'oi.id', '=', 'ri.purchase_order_item_id')
            ->join('stock_movements as m', 'm.id', '=', 'ri.stock_movement_id')
            ->whereIn('r.location_id', $locations)->where('r.status', 'posted')->whereNotNull('r.posted_at')->whereNotNull('r.posted_by')
            ->where('m.type', 'purchase')->whereNotNull('m.posting_payload_hash')
            ->where('m.reference_type', (new PurchaseReceiptItem)->getMorphClass())->whereColumn('m.reference_id', 'ri.id')
            ->whereColumn('m.location_id', 'r.location_id')->whereColumn('po.location_id', 'r.location_id')
            ->whereColumn('oi.purchase_order_id', 'po.id')->whereColumn('ri.purchase_order_id', 'po.id')
            ->whereColumn('oi.variant_id', 'm.variant_id')->whereColumn('m.quantity', 'ri.quantity')->where('ri.quantity', '>', 0)
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('stock_movements as reversal')->whereColumn('reversal.reverses_movement_id', 'm.id'));
    }

    public function query(User $actor): Builder
    {
        $access = app(InventoryAccess::class);
        $access->authorize($actor, InventoryPermissions::ALLOCATIONS_VIEW);
        $access->authorize($actor, InventoryPermissions::BALANCES);
        $locations = $access->locations($actor, InventoryPermissions::ALLOCATIONS_VIEW)->select(['id', 'name', 'is_active']);
        $evidence = DB::table('stock_movements as m')->join('inventory_size_allocation_items as i', 'i.id', '=', 'm.reference_id')
            ->join('inventory_size_allocations as a', 'a.id', '=', 'i.allocation_id')
            ->join('product_variants as source', 'source.id', '=', 'a.source_variant_id')
            ->join('product_variants as target', 'target.id', '=', 'i.variant_id')
            ->leftJoin('stock_movements as reversal', 'reversal.reverses_movement_id', '=', 'm.id')
            ->where('m.reference_type', (new SizeAllocationItem)->getMorphClass())->whereIn('a.location_id', (clone $locations)->select('id'))
            ->selectRaw('i.id AS item_id, SUM(CASE WHEN m.variant_id = a.source_variant_id AND m.quantity < 0 THEN -m.quantity ELSE 0 END) AS source_quantity, SUM(CASE WHEN m.variant_id = i.variant_id AND m.quantity > 0 THEN m.quantity ELSE 0 END) AS target_quantity')
            ->selectRaw("SUM(CASE WHEN source.product_id = a.product_id AND source.unallocated_product_id = a.product_id AND target.product_id = a.product_id AND target.unallocated_product_id IS NULL AND target.size_id IS NOT NULL AND m.location_id = a.location_id AND m.type = 'adjustment' AND m.posting_payload_hash IS NOT NULL AND reversal.id IS NULL AND ((m.variant_id = a.source_variant_id AND m.quantity < 0) OR (m.variant_id = i.variant_id AND m.quantity > 0)) THEN 0 ELSE 1 END) AS invalid_movements")
            ->groupBy('i.id');
        $items = DB::table('inventory_size_allocation_items as i')->leftJoinSub($evidence, 'e', 'e.item_id', '=', 'i.id')
            ->selectRaw('i.allocation_id, COUNT(*) AS item_count, SUM(i.quantity) AS quantity, SUM(CASE WHEN i.quantity > 0 AND e.source_quantity = i.quantity AND e.target_quantity = i.quantity AND e.invalid_movements = 0 THEN 0 ELSE 1 END) AS invalid_items')->groupBy('i.allocation_id');
        $completed = "a.status = 'posted' AND a.posted_at IS NOT NULL AND a.posted_by IS NOT NULL AND i.item_count > 0 AND i.invalid_items = 0";
        $history = DB::table('inventory_size_allocations as a')->leftJoinSub($items, 'i', 'i.allocation_id', '=', 'a.id')
            ->whereIn('a.location_id', (clone $locations)->select('id'))->whereIn('a.status', ['draft', 'posted'])
            ->selectRaw("a.source_variant_id, a.location_id, COUNT(*) AS document_count, SUM(CASE WHEN $completed THEN i.quantity ELSE 0 END) AS allocated_quantity, SUM(CASE WHEN $completed THEN 1 ELSE 0 END) AS completed_count, SUM(CASE WHEN a.status = 'posted' AND NOT (COALESCE(a.posted_at, '') <> '' AND a.posted_by IS NOT NULL AND COALESCE(i.item_count, 0) > 0 AND COALESCE(i.invalid_items, 1) = 0) THEN 1 ELSE 0 END) AS invalid_history")
            ->groupBy('a.source_variant_id', 'a.location_id');
        $receipts = $this->receipts($actor)->selectRaw('m.variant_id, r.location_id, SUM(ri.quantity) AS received_quantity, COUNT(DISTINCT r.id) AS receipt_count')->groupBy('m.variant_id', 'r.location_id');
        $pools = ProductVariant::query()->whereColumn('unallocated_product_id', 'product_id')->crossJoinSub($locations, 'pool_location')
            ->leftJoin('stock_balances as b', fn (JoinClause $j) => $j->on('b.variant_id', '=', 'product_variants.id')->on('b.location_id', '=', 'pool_location.id'))
            ->leftJoinSub($receipts, 'receipts', fn (JoinClause $j) => $j->on('receipts.variant_id', '=', 'product_variants.id')->on('receipts.location_id', '=', 'pool_location.id'))
            ->leftJoinSub($history, 'history', fn (JoinClause $j) => $j->on('history.source_variant_id', '=', 'product_variants.id')->on('history.location_id', '=', 'pool_location.id'))
            ->where(fn (Builder $q) => $q->whereNotNull('b.id')->orWhereNotNull('receipts.variant_id')->orWhereNotNull('history.source_variant_id'))
            ->select('product_variants.id', 'product_variants.uuid', 'product_variants.product_id', 'product_variants.unallocated_product_id', 'product_variants.sku', 'product_variants.barcode', 'product_variants.color_id', 'product_variants.size_id', 'product_variants.is_active',
                'pool_location.id as allocation_location_id', 'pool_location.name as location_name', 'pool_location.is_active as location_active', 'b.id as balance_id', 'b.quantity_on_hand as remaining_quantity', 'b.quantity_reserved', 'b.quantity_available')
            ->selectRaw('COALESCE(receipts.received_quantity, 0) AS received_quantity, COALESCE(receipts.receipt_count, 0) AS receipt_count, COALESCE(history.allocated_quantity, 0) AS allocated_quantity, COALESCE(history.completed_count, 0) AS completed_count, COALESCE(history.invalid_history, 0) AS invalid_history');
        $status = "CASE WHEN balance_id IS NULL OR received_quantity <= 0 OR invalid_history > 0 OR remaining_quantity < 0 OR quantity_reserved < 0 OR quantity_reserved > remaining_quantity OR quantity_available <> remaining_quantity - quantity_reserved OR received_quantity <> allocated_quantity + remaining_quantity OR color_id IS NOT NULL OR size_id IS NOT NULL THEN 'review' WHEN remaining_quantity > 0 AND allocated_quantity = 0 THEN 'unallocated' WHEN remaining_quantity > 0 AND allocated_quantity > 0 THEN 'partial' WHEN remaining_quantity = 0 AND completed_count > 0 AND allocated_quantity > 0 THEN 'full' ELSE 'review' END";
        $classified = ProductVariant::query()->fromSub($pools->toBase(), 'product_variants')->select('product_variants.*')->selectRaw($status.' AS allocation_state');

        return ProductVariant::query()->fromSub($classified->toBase(), 'product_variants')->select('product_variants.*')
            ->with(['product.brand', 'product.images', 'images'])
            ->withExists(['product as has_size_targets' => fn (Builder $q) => $q->whereHas('variants', fn (Builder $v) => $v->whereNull('unallocated_product_id')->where('is_active', true)->whereHas('size', fn (Builder $s) => $s->where('is_active', true)))]);
    }

    /** @param array<string, array<string, mixed>> $filters */
    public function filter(Builder $query, User $actor, array $filters): Builder
    {
        foreach (['location_id' => 'allocation_location_id', 'product_id' => 'product_id'] as $key => $column) {
            $query->when(filled($filters[$key]['value'] ?? null), fn (Builder $q) => $q->where($column, $filters[$key]['value']));
        }
        $brand = $filters['brand']['value'] ?? null;
        $query->when(filled($brand), fn (Builder $q) => $q->whereHas('product', fn (Builder $p) => $p->where('brand_id', $brand === 'none' ? null : $brand)));
        if (filled($filters['supplier']['value'] ?? null) || filled($filters['receipt']['value'] ?? null)) {
            if (! app(PurchasingAccess::class)->allows($actor, PurchasingPermissions::RECEIPTS_VIEW)) {
                return $query->whereRaw('1 = 0');
            }
            $receipts = $this->receipts($actor)->selectRaw('1')->whereColumn('m.variant_id', 'product_variants.id')->whereColumn('r.location_id', 'product_variants.allocation_location_id')
                ->when(filled($filters['supplier']['value'] ?? null), fn (QueryBuilder $q) => $q->where('po.supplier_id', $filters['supplier']['value']))
                ->when(filled($filters['receipt']['value'] ?? null), fn (QueryBuilder $q) => $q->where('r.id', $filters['receipt']['value']));
            $query->whereExists($receipts);
        }

        return $query;
    }

    public function status(Builder $query, string $status): Builder
    {
        return match ($status) {
            'needs' => $query->whereIn('allocation_state', ['unallocated', 'partial', 'review']),
            'unallocated', 'partial', 'full', 'review' => $query->where('allocation_state', $status),
            default => $query,
        };
    }

    /** @return array<string, int> */
    public function counts(Builder $query): array
    {
        $row = (clone $query)->reorder()->toBase()->select([])->selectRaw("COALESCE(SUM(CASE WHEN allocation_state <> 'full' THEN 1 ELSE 0 END), 0) AS needs, COALESCE(SUM(CASE WHEN allocation_state = 'unallocated' THEN 1 ELSE 0 END), 0) AS unallocated, COALESCE(SUM(CASE WHEN allocation_state = 'partial' THEN 1 ELSE 0 END), 0) AS partial, COALESCE(SUM(CASE WHEN allocation_state = 'full' THEN 1 ELSE 0 END), 0) AS full, COALESCE(SUM(CASE WHEN allocation_state = 'review' THEN 1 ELSE 0 END), 0) AS review")->first();

        return array_map(intval(...), (array) $row);
    }
}
