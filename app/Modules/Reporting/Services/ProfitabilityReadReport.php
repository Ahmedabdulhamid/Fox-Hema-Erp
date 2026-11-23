<?php

namespace App\Modules\Reporting\Services;

use App\Filament\ReadModelCache;
use App\Modules\AccessManagement\Models\User;
use App\Modules\InventoryCosting\Services\ProfitabilityCalculator;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProfitabilityReadReport extends SalesReport
{
    protected string $accessClass = ProfitabilityAccess::class;

    public function read(User $actor, string $from, string $until, ?int $locationId = null, string $currency = 'EGP', string $group = 'variant', int $page = 1): array
    {
        $locations = $this->locations($actor, $from, $until, $locationId);
        Validator::make(compact('currency', 'group', 'page'), [
            'currency' => ['required', 'in:EGP'], 'group' => ['required', 'in:product,variant'], 'page' => ['integer', 'min:1'],
        ])->validate();
        $dependencies = [];
        for ($date = CarbonImmutable::parse($from); $date->toDateString() <= $until; $date = $date->addDay()) {
            foreach ($locations as $id) {
                $dependencies[] = ProfitabilityProjection::epoch($id, $date->toDateString());
            }
        }

        return app(ReadModelCache::class)->remember('reporting_profitability', [$locations, $from, $until, $currency, $group, $page],
            fn (): array => DB::transaction(function () use ($locations, $from, $until, $currency, $group, $page): array {
                $base = DB::table('reporting_profitability_daily as s')->whereIn('s.location_id', $locations)
                    ->whereBetween('s.report_date', [$from, $until])->where('s.currency', $currency);
                $totals = $this->amounts((array) $this->aggregate(clone $base)->first());
                $days = $this->aggregate(clone $base)->addSelect('s.report_date')->groupBy('s.report_date')->orderBy('s.report_date')
                    ->get()->keyBy('report_date');
                $daily = [];
                for ($date = CarbonImmutable::parse($from); $date->toDateString() <= $until; $date = $date->addDay()) {
                    $daily[] = ['date' => $date->toDateString()] + $this->amounts((array) ($days->get($date->toDateString()) ?? []));
                }
                $breakdown = $this->grouped(clone $base, $group)->orderBy('id')->paginate(25, ['*'], 'page', $page);
                $top = $this->grouped(clone $base, 'variant')->havingRaw('SUM(s.missing) = 0')
                    ->orderByRaw('SUM(s.revenue - s.known_cost) DESC')->orderBy('id')->limit(10)->get();

                return ['totals' => $totals, 'daily' => $daily, 'currency' => $currency,
                    'breakdown' => $breakdown->getCollection()->map(fn ($row): array => $this->amounts((array) $row))->all(),
                    'total_rows' => $breakdown->total(), 'page' => $page, 'per_page' => 25,
                    'top' => $top->map(fn ($row): array => $this->amounts((array) $row))->all()];
            }), config('read_cache.report_ttl', 30), lock: true, dependencies: $dependencies);
    }

    public function freshness(User $actor, string $from, string $until, ?int $locationId = null): array
    {
        return app(DailyProjectionState::class)->freshness('profitability', $this->locations($actor, $from, $until, $locationId), $from, $until);
    }

    private function aggregate(Builder $query): Builder
    {
        $query->selectRaw('COALESCE(SUM(s.missing), 0) AS missing');
        foreach (ProfitabilityProjection::AMOUNTS as $field) {
            $query->selectRaw('COALESCE(SUM(s.'.$field.'), 0) AS '.$field);
        }

        return $query;
    }

    private function grouped(Builder $base, string $group): Builder
    {
        $query = $base->join('product_variants as v', 'v.id', '=', 's.variant_id')->join('products as p', 'p.id', '=', 'v.product_id');
        if ($group === 'product') {
            return $this->aggregate($query)->selectRaw('p.id AS id, p.name AS label')->groupBy('p.id', 'p.name');
        }

        $query->leftJoin('brands as brand', 'brand.id', '=', 'p.brand_id')
            ->leftJoin('colors as variant_color', 'variant_color.id', '=', 'v.color_id')
            ->leftJoin('sizes as variant_size', 'variant_size.id', '=', 'v.size_id');

        return $this->aggregate($query)->selectRaw('v.id AS id, v.sku AS label, brand.name AS brand_name,
            variant_color.name AS color_name, variant_color.hex_code AS color_hex, variant_size.name AS size_name')
            ->groupBy('v.id', 'v.sku', 'brand.name', 'variant_color.name', 'variant_color.hex_code', 'variant_size.name');
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function amounts(array $row): array
    {
        $missing = (int) ($row['missing'] ?? 0);
        $result = app(ProfitabilityCalculator::class)->calculate((string) ($row['revenue'] ?? '0'), $missing > 0 ? null : (string) ($row['known_cost'] ?? '0'));
        foreach (['return_quantity', 'return_revenue', 'return_loss', 'return_variance', 'credit_adjustment'] as $field) {
            $result[$field] = (string) BigDecimal::of((string) ($row[$field] ?? '0'))->toScale(4);
        }

        return $result + ['missing' => $missing] + array_intersect_key($row, array_flip([
            'id', 'label', 'brand_name', 'color_name', 'color_hex', 'size_name',
        ]));
    }
}
