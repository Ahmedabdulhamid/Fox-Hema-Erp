<?php

namespace App\Modules\Reporting\Services;

use App\Filament\ReadModelCache;
use App\Modules\AccessManagement\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SalesReport
{
    protected string $accessClass = ReportingAccess::class;

    /** @return list<int> */
    public function locations(User $actor, string $from, string $until, ?int $locationId): array
    {
        Validator::make(compact('from', 'until'), [
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();
        abort_if(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($until)) > 365, 422, __('reporting.validation.max_period'));

        return array_keys(app($this->accessClass)->branches($actor, $locationId));
    }

    /** @return array<string, mixed> */
    public function read(User $actor, string $from, string $until, ?int $locationId = null, string $currency = 'EGP'): array
    {
        $locations = $this->locations($actor, $from, $until, $locationId);
        Validator::make(['currency' => $currency], ['currency' => ['required', 'regex:/^[A-Z]{3}$/D']])->validate();
        $dependencies = [];
        for ($date = CarbonImmutable::parse($from); $date->toDateString() <= $until; $date = $date->addDay()) {
            foreach ($locations as $id) {
                $dependencies[] = SalesProjection::epoch($id, $date->toDateString());
            }
        }
        foreach ($locations as $id) {
            $dependencies[] = CashReportingInvalidation::currentEpoch('pos', $id);
        }

        return app(ReadModelCache::class)->remember('reporting_sales', [$locations, $from, $until, $currency],
            function () use ($locations, $from, $until, $currency): array {
                $daily = $this->query('reporting_sales_daily', $locations, $from, $until, $currency);
                $totals = (array) (clone $daily)->selectRaw('COALESCE(SUM(invoice_count), 0) AS invoice_count, COALESCE(SUM(credit_count), 0) AS credit_count,
                    COALESCE(SUM(invoice_total), 0) AS invoiced_total, COALESCE(SUM(credit_total), 0) AS credited_total,
                    COALESCE(SUM(invoice_discount - credit_discount), 0) AS net_discount,
                    COALESCE(SUM(invoice_tax - credit_tax), 0) AS net_tax,
                    COALESCE(SUM(invoice_subtotal - invoice_discount - credit_subtotal + credit_discount), 0) AS net_revenue,
                    COALESCE(SUM(invoice_total - credit_total), 0) AS net_total')->first();
                foreach ($totals as $key => $value) {
                    $totals[$key] = str_ends_with($key, '_count') ? (int) $value : $this->decimal($value);
                }
                $days = (clone $daily)->selectRaw('report_date, SUM(invoice_subtotal - invoice_discount - credit_subtotal + credit_discount) AS net_revenue')
                    ->groupBy('report_date')->orderBy('report_date')->get()->mapWithKeys(fn ($row): array => [$row->report_date => $this->decimal($row->net_revenue)])->all();
                $chart = [];
                for ($date = CarbonImmutable::parse($from); $date->toDateString() <= $until; $date = $date->addDay()) {
                    $chart[] = ['date' => $date->toDateString(), 'net_revenue' => $days[$date->toDateString()] ?? '0.0000'];
                }
                $variants = $this->query('reporting_sales_variant_daily', $locations, $from, $until, $currency)
                    ->selectRaw('variant_id, SUM(invoice_quantity - credit_quantity) AS net_quantity,
                        SUM(invoice_subtotal - invoice_discount - credit_subtotal + credit_discount) AS net_revenue')
                    ->groupBy('variant_id')->orderByDesc('net_quantity')->orderBy('variant_id')->limit(10);
                $top = DB::query()->fromSub($variants, 'ranked')->join('product_variants as v', 'v.id', '=', 'ranked.variant_id')
                    ->join('products as p', 'p.id', '=', 'v.product_id')
                    ->leftJoin('brands as brand', 'brand.id', '=', 'p.brand_id')
                    ->leftJoin('colors as variant_color', 'variant_color.id', '=', 'v.color_id')
                    ->leftJoin('sizes as variant_size', 'variant_size.id', '=', 'v.size_id')
                    ->orderByDesc('ranked.net_quantity')->orderBy('ranked.variant_id')
                    ->get(['ranked.*', 'v.sku', 'p.name', 'brand.name as brand_name', 'variant_color.name as color_name',
                        'variant_color.hex_code as color_hex', 'variant_size.name as size_name'])->map(fn ($row): array => [
                            'variant_id' => $row->variant_id, 'sku' => $row->sku, 'name' => $row->name,
                            'brand_name' => $row->brand_name, 'color_name' => $row->color_name,
                            'color_hex' => $row->color_hex, 'size_name' => $row->size_name,
                            'net_quantity' => $this->decimal($row->net_quantity), 'net_revenue' => $this->decimal($row->net_revenue),
                        ])->all();

                return [
                    'totals' => $totals,
                    'daily' => $chart,
                    'variants' => $top,
                    'collections' => app(PosCollectionReport::class)->summarize($locations, $from, $until, $currency),
                    'currency' => $currency,
                ];
            }, config('read_cache.report_ttl', 30), lock: true, dependencies: $dependencies);
    }

    /** @return array{projected_days: int, expected_days: int, pending_days: int, oldest_projection: ?string} */
    public function freshness(User $actor, string $from, string $until, ?int $locationId = null): array
    {
        $locations = $this->locations($actor, $from, $until, $locationId);

        return app(DailyProjectionState::class)->freshness('sales', $locations, $from, $until);
    }

    /** @param list<int> $locations */
    private function query(string $table, array $locations, string $from, string $until, string $currency): Builder
    {
        return DB::table($table)->whereIn('location_id', $locations)->whereBetween('report_date', [$from, $until])->where('currency', $currency);
    }

    private function decimal(string|int|float $amount): string
    {
        return (string) BigDecimal::of((string) $amount)->toScale(4);
    }
}
