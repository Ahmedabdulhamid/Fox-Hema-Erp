<?php

namespace App\Modules\Reporting\Services;

use App\Filament\ReadModelCache;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Services\InventoryInsights;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InventoryReadReport extends SalesReport
{
    protected string $accessClass = InventoryReportingAccess::class;

    public function locations(User $actor, string $from, string $until, ?int $locationId): array
    {
        $locations = parent::locations($actor, $from, $until, $locationId);
        Validator::make(compact('until'), ['until' => 'before_or_equal:today'])->validate();

        return $locations;
    }

    /** @return array<string, mixed> */
    public function read(User $actor, string $from, string $until, ?int $locationId = null, string $currency = 'EGP', int $page = 1, int $movementPage = 1, int $closingPage = 1): array
    {
        $locations = $this->locations($actor, $from, $until, $locationId);
        Validator::make(compact('currency', 'page', 'movementPage', 'closingPage'), ['currency' => 'in:EGP', 'page' => 'integer|min:1', 'movementPage' => 'integer|min:1', 'closingPage' => 'integer|min:1'])->validate();
        $costs = app(InventoryReportingAccess::class)->costs($actor, $locationId);
        $currentDeps = array_map(fn (int $id): string => InventoryReportingInvalidation::currentEpoch($id), $locations);
        $current = app(ReadModelCache::class)->remember('reporting_inventory', [$locations, 'current', $costs, $page], function () use ($locations, $costs, $page): array {
            $query = $this->current($locations, $costs);
            $totals = (clone $query)->selectRaw('COUNT(*) AS rows_count, COALESCE(SUM(balance.quantity_on_hand),0) AS on_hand,
                COALESCE(SUM(balance.quantity_reserved),0) AS reserved, COALESCE(SUM(balance.quantity_available),0) AS available')
                ->selectRaw('COALESCE(SUM(CASE WHEN '.app(InventoryInsights::class)->lowStockCondition().' THEN 1 ELSE 0 END),0) AS low_count');
            if ($costs) {
                $value = app(InventoryInsights::class)->valuationExpression();
                $totals->selectRaw("SUM(CASE WHEN ($value) IS NULL THEN 1 ELSE 0 END) AS missing_cost,
                    CASE WHEN SUM(CASE WHEN ($value) IS NULL THEN 1 ELSE 0 END) > 0 THEN NULL ELSE COALESCE(SUM($value),0) END AS value");
            }
            $columns = ['balance.location_id', 'balance.variant_id', 'l.name as location', 'v.sku', 'p.name as product',
                'brand.name as brand_name', 'variant_color.name as color_name', 'variant_color.hex_code as color_hex',
                'variant_size.name as size_name', 'balance.quantity_on_hand', 'balance.quantity_reserved',
                'balance.quantity_available', 'policy.reorder_threshold'];
            $stock = (clone $query)->select($columns);
            if ($costs) {
                $stock->selectRaw(app(InventoryInsights::class)->valuationExpression().' AS value');
            }

            return ['totals' => (array) $totals->first(),
                'stock' => $this->page((clone $stock)->orderBy('balance.location_id')->orderBy('balance.variant_id'), $page, 'stockPage'),
                'low' => (clone $stock)->whereRaw(app(InventoryInsights::class)->lowStockCondition())->orderBy('balance.quantity_available')->orderBy('balance.variant_id')->limit(10)->get()->map(fn ($row): array => (array) $row)->all()];
        }, config('read_cache.report_ttl'), dependencies: $currentDeps);
        $history = app(ReadModelCache::class)->remember('reporting_inventory', [$locations, 'history', $from, $until, $movementPage], function () use ($locations, $from, $until, $movementPage): array {
            $query = DB::table('stock_movements as m')->join('product_variants as v', 'v.id', '=', 'm.variant_id')
                ->join('products as p', 'p.id', '=', 'v.product_id')->join('locations as l', 'l.id', '=', 'm.location_id')
                ->leftJoin('brands as brand', 'brand.id', '=', 'p.brand_id')
                ->leftJoin('colors as variant_color', 'variant_color.id', '=', 'v.color_id')
                ->leftJoin('sizes as variant_size', 'variant_size.id', '=', 'v.size_id')
                ->whereIn('m.location_id', $locations)->where('m.created_at', '>=', $from.' 00:00:00')
                ->where('m.created_at', '<', CarbonImmutable::parse($until)->addDay()->toDateString().' 00:00:00')
                ->select('m.id', 'm.created_at', 'm.location_id', 'm.variant_id', 'm.type', 'm.quantity', 'm.reverses_movement_id',
                    'v.sku', 'l.name as location', 'brand.name as brand_name', 'variant_color.name as color_name',
                    'variant_color.hex_code as color_hex', 'variant_size.name as size_name');

            return $this->page($query->orderByDesc('m.id'), $movementPage, 'movementPage');
        }, config('read_cache.report_ttl'), dependencies: $currentDeps);
        $closing = app(ReadModelCache::class)->remember('reporting_inventory', [$locations, 'closing', $until, $costs, $closingPage], function () use ($locations, $until, $costs, $closingPage): array {
            $query = DB::table('reporting_inventory_daily as s')->whereIn('s.location_id', $locations)->where('s.report_date', $until);
            $totals = (clone $query)->selectRaw('SUM(CASE WHEN closing_quantity IS NULL THEN 1 ELSE 0 END) AS missing_quantity,
                CASE WHEN COUNT(*) = 0 OR SUM(CASE WHEN closing_quantity IS NULL THEN 1 ELSE 0 END) > 0 THEN NULL ELSE SUM(closing_quantity) END AS quantity');
            if ($costs) {
                $totals->selectRaw('SUM(CASE WHEN closing_value IS NULL THEN 1 ELSE 0 END) AS missing_cost,
                CASE WHEN COUNT(*) = 0 OR SUM(CASE WHEN closing_value IS NULL THEN 1 ELSE 0 END) > 0 THEN NULL ELSE SUM(closing_value) END AS value');
            }
            $columns = ['s.location_id', 's.variant_id', 's.closing_quantity', 'v.sku', 'l.name as location',
                'brand.name as brand_name', 'variant_color.name as color_name', 'variant_color.hex_code as color_hex',
                'variant_size.name as size_name'];
            if ($costs) {
                $columns[] = 's.closing_value';
            }
            $rows = $query->join('product_variants as v', 'v.id', '=', 's.variant_id')
                ->join('products as p', 'p.id', '=', 'v.product_id')->join('locations as l', 'l.id', '=', 's.location_id')
                ->leftJoin('brands as brand', 'brand.id', '=', 'p.brand_id')
                ->leftJoin('colors as variant_color', 'variant_color.id', '=', 'v.color_id')
                ->leftJoin('sizes as variant_size', 'variant_size.id', '=', 'v.size_id')
                ->select($columns)->orderBy('s.location_id')->orderBy('s.variant_id');

            return ['totals' => (array) $totals->first(), 'rows' => $this->page($rows, $closingPage, 'closingPage')];
        }, config('read_cache.report_ttl'), dependencies: array_map(fn (int $id): string => InventoryProjection::epoch($id, $until), $locations));
        $freshness = $this->freshness($actor, $from, $until, $locationId);
        $endFreshness = app(DailyProjectionState::class)->freshness('inventory', $locations, $until, $until);
        if ($endFreshness['projected_days'] !== count($locations) || $endFreshness['pending_days'] > 0) {
            $closing['totals']['quantity'] = null;
            if ($costs) {
                $closing['totals']['value'] = null;
            }
        }

        return $current + ['costs' => $costs, 'history' => $history, 'closing' => $closing, 'freshness' => $freshness, 'trend' => $this->trend($actor, $from, $until, $locationId)];
    }

    /** @return array<string, mixed> */
    public function trend(User $actor, string $from, string $until, ?int $locationId = null): array
    {
        $locations = $this->locations($actor, $from, $until, $locationId);
        $deps = [];
        for ($date = CarbonImmutable::parse($from); $date->toDateString() <= $until; $date = $date->addDay()) {
            foreach ($locations as $id) {
                $deps[] = InventoryProjection::epoch($id, $date->toDateString());
            }
        }

        return app(ReadModelCache::class)->remember('reporting_inventory', [$locations, 'trend', $from, $until], function () use ($locations, $from, $until): array {
            $base = DB::table('reporting_inventory_movement_daily')->whereIn('location_id', $locations)->whereBetween('report_date', [$from, $until]);
            $aggregate = 'SUM(received_quantity) AS received, SUM(issued_quantity) AS issued, SUM(movement_count) AS movements, SUM(reversal_count) AS reversals';
            $byDay = (clone $base)->selectRaw('report_date, '.$aggregate)->groupBy('report_date')->get()->keyBy('report_date');
            $daily = [];
            for ($date = CarbonImmutable::parse($from); $date->toDateString() <= $until; $date = $date->addDay()) {
                $daily[] = ['date' => $date->toDateString()] + (array) ($byDay->get($date->toDateString()) ?? ['received' => '0.0000', 'issued' => '0.0000', 'movements' => 0, 'reversals' => 0]);
            }

            return ['daily' => $daily, 'types' => (clone $base)->selectRaw('type, '.$aggregate)->groupBy('type')->orderBy('type')->get()->map(fn ($row): array => (array) $row)->all()];
        }, config('read_cache.report_ttl'), dependencies: $deps);
    }

    public function freshness(User $actor, string $from, string $until, ?int $locationId = null): array
    {
        return app(DailyProjectionState::class)->freshness('inventory', $this->locations($actor, $from, $until, $locationId), $from, $until);
    }

    /** @param list<int> $locations */
    private function current(array $locations, bool $costs): Builder
    {
        $query = DB::table('stock_balances as balance')->join('product_variants as v', 'v.id', '=', 'balance.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')->join('locations as l', 'l.id', '=', 'balance.location_id')
            ->leftJoin('brands as brand', 'brand.id', '=', 'p.brand_id')
            ->leftJoin('colors as variant_color', 'variant_color.id', '=', 'v.color_id')
            ->leftJoin('sizes as variant_size', 'variant_size.id', '=', 'v.size_id')
            ->leftJoin('inventory_stock_policies as policy', fn ($join) => $join->on('policy.location_id', '=', 'balance.location_id')->on('policy.variant_id', '=', 'balance.variant_id'))
            ->whereIn('balance.location_id', $locations);
        if ($costs) {
            $query->leftJoin('inventory_cost_states as valuation', fn ($join) => $join->on('valuation.location_id', '=', 'balance.location_id')->on('valuation.variant_id', '=', 'balance.variant_id'));
        }

        return $query;
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int} */
    private function page(Builder $query, int $page, string $name): array
    {
        $result = $query->paginate(25, ['*'], $name, $page);

        return ['items' => $result->getCollection()->map(fn ($row): array => (array) $row)->all(), 'total' => $result->total(), 'page' => $page, 'per_page' => 25];
    }
}
