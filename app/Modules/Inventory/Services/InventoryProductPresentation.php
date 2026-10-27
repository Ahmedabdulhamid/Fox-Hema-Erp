<?php

namespace App\Modules\Inventory\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InventoryProductPresentation
{
    public function canAllocate(User $actor, ProductVariant $pool): bool
    {
        return in_array($pool->allocation_state, ['unallocated', 'partial'], true) && (float) $pool->quantity_available > 0
            && $pool->has_size_targets && $pool->is_active && $pool->location_active
            && app(InventoryAccess::class)->allows($actor, InventoryPermissions::ALLOCATIONS_MANAGE, (int) $pool->allocation_location_id);
    }

    public function allocationPool(User $actor, int $productId, int $locationId): ?ProductVariant
    {
        if (! app(InventoryAccess::class)->allows($actor, InventoryPermissions::ALLOCATIONS_VIEW, $locationId)) {
            return null;
        }

        return app(AllocationStockStatus::class)->query($actor)->where('product_id', $productId)->where('allocation_location_id', $locationId)->first();
    }

    /** @return array<string, mixed> */
    public function details(User $actor, int $productId, ?int $locationId = null, bool $loadMatrix = true, int $page = 1): array
    {
        $access = app(InventoryAccess::class);
        $access->authorize($actor, InventoryPermissions::BALANCES, $locationId);

        $locations = $access->locations($actor, InventoryPermissions::BALANCES)->orderBy('name')->get();
        $location = $locationId === null ? $locations->first() : $locations->firstWhere('id', $locationId);
        abort_unless($location, 404);
        $insights = app(InventoryInsights::class);
        $query = $insights->query($actor, ['product' => $productId]);

        return ['product' => Product::with(['images', 'brand'])->findOrFail($productId), 'location' => $location, 'locations' => $locations,
            'summary' => $insights->summary($query), 'locationGroups' => $this->paginate($query, 'insights', $page, 'drawerLocationsPage'),
            'allocationPool' => $loadMatrix ? $this->allocationPool($actor, $productId, $location->id) : null,
            'matrix' => $loadMatrix ? $insights->matrix($actor, $productId, $location->id, 'available') : null];
    }

    /**
     * Read-only grouping of an already filtered, authorized stock query.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    public function paginate(Builder $query, string $kind, int $page = 1, string $pageName = 'page', string $sort = 'sku', bool $groupLocations = true, string $secondarySort = 'sku'): LengthAwarePaginator
    {
        abort_unless(in_array($kind, ['insights', 'balances', 'quarantine'], true), 422);
        $actor = auth()->user();
        $permission = $kind === 'quarantine' ? InventoryPermissions::QUARANTINE_VIEW : InventoryPermissions::BALANCES;
        $access = app(InventoryAccess::class);
        $access->authorize($actor, $permission);
        $source = (clone $query)->reorder();
        if ($kind === 'insights') {
            $source->select(['product_id', 'inventory_location_id as location_id', 'id as variant_id', 'unallocated_product_id', 'sku',
                'quantity_on_hand', 'quantity_reserved', 'quantity_available', 'net_sold', 'last_sale_at']);
            $source->whereIn('inventory_location_id', $access->locations($actor, $permission)->select('id'));
        } else {
            $table = $source->getModel()->getTable();
            $source = $access->scope($source, $actor, $permission)
                ->join('product_variants as grouped_variant', 'grouped_variant.id', '=', $table.'.variant_id')
                ->select(['grouped_variant.product_id', $table.'.location_id', $table.'.variant_id', 'grouped_variant.unallocated_product_id', 'grouped_variant.sku']);
            if ($kind === 'quarantine') {
                $source->selectRaw('quantity AS quantity_on_hand, NULL AS quantity_reserved, NULL AS quantity_available, 0 AS net_sold, NULL AS last_sale_at');
            } else {
                $source->addSelect(['quantity_on_hand', 'quantity_reserved', 'quantity_available'])->selectRaw('0 AS net_sold, NULL AS last_sale_at');
            }
        }
        $groups = DB::query()->fromSub($source->toBase(), 'stock_rows')->select('product_id')
            ->selectRaw('MIN(variant_id) AS id, MIN(sku) AS sku, COUNT(DISTINCT variant_id) AS variants, COUNT(DISTINCT location_id) AS location_count, SUM(quantity_on_hand) AS total_quantity_on_hand, SUM(CASE WHEN quantity_on_hand IS NULL THEN 1 ELSE 0 END) AS missing_balances, SUM(CASE WHEN unallocated_product_id IS NOT NULL AND quantity_on_hand IS NULL THEN 1 ELSE 0 END) AS unallocated_missing_balances, SUM(net_sold) AS net_sold, MAX(last_sale_at) AS last_sale_at')
            ->groupBy('product_id');
        if ($groupLocations) {
            $groups->addSelect('location_id')->groupBy('location_id');
        } else {
            $groups->selectRaw('MIN(location_id) AS location_id');
        }
        foreach (['quantity_on_hand', 'quantity_reserved', 'quantity_available'] as $field) {
            $groups->selectRaw("SUM(CASE WHEN unallocated_product_id IS NULL THEN $field END) AS $field")
                ->selectRaw("SUM(CASE WHEN unallocated_product_id IS NOT NULL THEN $field ELSE 0 END) AS unallocated_$field");
        }
        [$column, $direction] = match ($sort) {
            'available_asc' => ['quantity_available', 'asc'], 'available_desc' => ['quantity_available', 'desc'],
            'sold' => ['net_sold', 'desc'], 'sold_asc' => ['net_sold', 'asc'], 'last_sale' => ['last_sale_at', 'desc'], default => ['sku', 'asc'],
        };
        $groups->orderBy($column, $direction);
        if ($kind === 'insights' && $secondarySort !== $sort) {
            [$secondaryColumn, $secondaryDirection] = match ($secondarySort) {
                'available_asc' => ['quantity_available', 'asc'], 'available_desc' => ['quantity_available', 'desc'],
                'sold' => ['net_sold', 'desc'], 'sold_asc' => ['net_sold', 'asc'], 'last_sale' => ['last_sale_at', 'desc'], default => ['sku', 'asc'],
            };
            $groups->orderBy($secondaryColumn, $secondaryDirection);
        }
        $rows = $groups->orderBy('product_id')->orderBy('location_id')->paginate(25, ['*'], $pageName, $page)->onEachSide(0);
        $variants = ProductVariant::whereIn('id', $rows->pluck('id'))->with(['product.images', 'product.brand', 'images'])->get()->keyBy('id');
        $locations = Location::whereIn('id', $rows->pluck('location_id'))->pluck('name', 'id');
        $pools = collect();
        if ($kind !== 'quarantine' && $rows->isNotEmpty() && $access->allows($actor, InventoryPermissions::ALLOCATIONS_VIEW)) {
            $poolQuery = app(AllocationStockStatus::class)->query($actor)->whereIn('product_id', $rows->pluck('product_id'));
            $poolQuery->whereIn('allocation_location_id', $groupLocations ? $rows->pluck('location_id') : DB::query()->fromSub($source->toBase(), 'permitted_stock_locations')->select('location_id'));
            $pools = $poolQuery->get()->keyBy(fn (ProductVariant $pool): string => $pool->product_id.'-'.$pool->allocation_location_id);
        }
        foreach ($rows as $row) {
            $row->variant = $variants->get($row->id);
            $row->location_name = $locations[$row->location_id];
            $row->pool = $pools->get($row->product_id.'-'.$row->location_id);
            $row->pools = $groupLocations ? collect($row->pool ? [$row->pool] : []) : $pools->where('product_id', $row->product_id)->values();
        }

        return $rows;
    }
}
