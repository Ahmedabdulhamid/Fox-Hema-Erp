<?php

namespace App\Modules\Reporting\Services;

use App\Filament\ReadModelCache;
use App\Modules\AccessManagement\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PurchasingReadReport extends SalesReport
{
    protected string $accessClass = PurchasingReportingAccess::class;

    public function read(User $actor, string $from, string $until, ?int $locationId = null, string $currency = 'EGP', ?int $supplierId = null, ?int $variantId = null, int $page = 1): array
    {
        $locations = $this->locations($actor, $from, $until, $locationId);
        Validator::make(compact('currency', 'supplierId', 'variantId', 'page'), [
            'currency' => 'required|in:EGP', 'supplierId' => 'nullable|integer|min:1',
            'variantId' => 'nullable|integer|min:1', 'page' => 'integer|min:1',
        ], [], array_merge(__('reporting.validation.attributes'), [
            'supplierId' => __('purchasing_reporting.supplier'),
            'variantId' => __('reporting.filters.variant'),
            'page' => __('purchasing_reporting.page'),
        ]))->validate();
        if ($supplierId !== null) {
            abort_unless(DB::table('suppliers')->where('id', $supplierId)->exists(), 403);
        }
        if ($variantId !== null) {
            abort_unless(DB::table('product_variants')->where('id', $variantId)->exists(), 403);
        }
        $dates = TreasuryReadReport::dates($from, $until);
        $dependencies = ['catalog', 'suppliers'];
        foreach ($locations as $id) {
            $dependencies[] = 'reporting-purchasing-current:'.$id;
            foreach ($dates as $date) {
                $dependencies[] = DailyProjectionState::epoch('purchasing', $id, $date);
            }
        }
        $data = app(ReadModelCache::class)->remember('reporting_purchasing', [$locations, $from, $until, $currency, $supplierId, $variantId, $page],
            function () use ($locations, $from, $until, $currency, $supplierId, $variantId, $page, $dates): array {
                $base = DB::table('reporting_purchasing_daily as d')->whereIn('d.location_id', $locations)
                    ->whereBetween('d.report_date', [$from, $until])->where('d.currency', $currency)
                    ->when($supplierId !== null, fn ($q) => $q->where('d.supplier_id', $supplierId));
                $events = $this->aggregate(clone $base)->addSelect('d.event')->groupBy('d.event')->get()->keyBy('event');
                $totals = [];
                foreach (['order', 'order_line', 'receipt', 'receipt_line', 'bill', 'bill_line', 'payment', 'recovery'] as $event) {
                    $totals[$event] = isset($events[$event]) ? (array) $events[$event] : array_fill_keys(PurchasingReportingEvidence::FIELDS, '0.0000');
                    if ((float) $totals[$event]['missing'] > 0) {
                        foreach (['amount', 'quantity', 'remaining'] as $field) {
                            $totals[$event][$field] = null;
                        }
                    }
                }
                $trendRows = (clone $base)->where('d.event', 'receipt_line')->selectRaw('d.report_date,SUM(d.quantity) AS quantity,SUM(d.missing) AS missing')
                    ->groupBy('d.report_date')->get()->keyBy('report_date');
                $coverage = DB::table('reporting_purchasing_partitions')->whereIn('location_id', $locations)->whereBetween('report_date', [$from, $until])
                    ->whereColumn('revision', 'projected_revision')->whereNotNull('projected_at')->selectRaw('report_date,COUNT(*) AS coverage')->groupBy('report_date')->pluck('coverage', 'report_date');
                $daily = array_map(fn (string $date): array => ['date' => $date, 'quantity' => (int) ($coverage[$date] ?? 0) !== count($locations) || (float) ($trendRows[$date]->missing ?? 0) > 0
                        ? null : ($trendRows[$date]->quantity ?? '0.0000')], $dates);
                $rows = $this->aggregate((clone $base)->join('suppliers as s', 's.id', '=', 'd.supplier_id')->join('locations as l', 'l.id', '=', 'd.location_id'))
                    ->addSelect('d.report_date', 'd.supplier_id', 's.name as supplier', 'd.location_id', 'l.name as location', 'd.event', 'd.status')
                    ->whereIn('d.event', ['order', 'receipt', 'bill', 'payment', 'recovery'])
                    ->groupBy('d.report_date', 'd.supplier_id', 's.name', 'd.location_id', 'l.name', 'd.event', 'd.status')->orderByDesc('d.report_date')->orderBy('d.supplier_id')->orderBy('d.location_id')->orderBy('d.event')->orderBy('d.status')->paginate(25, ['*'], 'page', $page);
                $variants = $this->aggregate((clone $base)->whereIn('d.event', ['order_line', 'receipt_line', 'bill_line'])
                    ->when($variantId !== null, fn ($q) => $q->where('d.variant_id', $variantId))
                    ->join('suppliers as s', 's.id', '=', 'd.supplier_id')->join('locations as l', 'l.id', '=', 'd.location_id')
                    ->leftJoin('product_variants as v', 'v.id', '=', 'd.variant_id')->leftJoin('products as p', 'p.id', '=', 'v.product_id')
                    ->leftJoin('brands as brand', 'brand.id', '=', 'p.brand_id')->leftJoin('colors as c', 'c.id', '=', 'v.color_id')->leftJoin('sizes as z', 'z.id', '=', 'v.size_id'))
                    ->addSelect('d.variant_id', 'd.event', 's.name as supplier', 'l.name as location', 'brand.name as brand_name', 'c.name as color_name', 'c.hex_code as color_hex', 'z.name as size_name')
                    ->groupBy('d.variant_id', 'd.event', 'd.supplier_id', 's.name', 'd.location_id', 'l.name', 'brand.name', 'c.name', 'c.hex_code', 'z.name')
                    ->orderBy('d.variant_id')->orderBy('d.supplier_id')->orderBy('d.location_id')->orderBy('d.event')->paginate(25, ['*'], 'page', $page);
                $balances = DB::table('reporting_purchasing_balances as b')->join('suppliers as s', 's.id', '=', 'b.supplier_id')->join('locations as l', 'l.id', '=', 'b.location_id')
                    ->whereIn('b.location_id', $locations)->where('b.currency', $currency)->when($supplierId !== null, fn ($q) => $q->where('b.supplier_id', $supplierId))
                    ->orderBy('b.supplier_id')->orderBy('b.location_id')->paginate(25, ['b.*', 's.name as supplier', 'l.name as location'], 'page', $page);
                $balanceMissing = DB::table('reporting_purchasing_balances')->whereIn('location_id', $locations)->where('currency', $currency)
                    ->when($supplierId !== null, fn ($q) => $q->where('supplier_id', $supplierId))->where('missing', '>', 0)->exists();

                return ['balance_missing' => $balanceMissing, 'totals' => $totals, 'daily' => $daily, 'rows' => $rows->getCollection()->map(fn ($row): array => (array) $row)->all(), 'total' => $rows->total(),
                    'variants' => $variants->getCollection()->map(fn ($row): array => (array) $row)->all(), 'variant_total' => $variants->total(),
                    'balances' => $balances->getCollection()->map(fn ($row): array => (array) $row)->all(), 'balance_total' => $balances->total()];
            }, config('read_cache.report_ttl'), dependencies: $dependencies);
        $freshness = app(DailyProjectionState::class)->freshness('purchasing', $locations, $from, $until);
        if ($freshness['pending_days'] > 0 || $freshness['projected_days'] !== $freshness['expected_days']) {
            foreach ($data['totals'] as &$total) {
                foreach (['quantity', 'amount', 'remaining'] as $field) {
                    $total[$field] = null;
                }
            }
            unset($total);
        }
        $pending = DB::table('reporting_purchasing_partitions')->whereIn('location_id', $locations)->whereColumn('revision', '>', 'projected_revision')->exists();
        foreach (['rows', 'variants'] as $collection) {
            foreach ($data[$collection] as &$row) {
                if ((float) $row['missing'] > 0) {
                    foreach (['amount', 'quantity', 'remaining'] as $field) {
                        $row[$field] = null;
                    }
                }
            }
            unset($row);
        }
        foreach ($data['balances'] as &$balance) {
            if ($pending || (float) $balance['missing'] > 0) {
                foreach (['billed', 'paid', 'outstanding', 'advances', 'net_balance'] as $field) {
                    $balance[$field] = null;
                }
            }
        }
        unset($balance);
        if ($data['balance_missing']) {
            foreach (['bill', 'payment'] as $event) {
                $data['totals'][$event]['amount'] = null;
            }
        }

        return $data + ['freshness' => $freshness, 'balances_pending' => $pending, 'page' => $page];
    }

    private function aggregate(Builder $query): Builder
    {
        foreach (PurchasingReportingEvidence::FIELDS as $field) {
            $query->selectRaw('COALESCE(SUM(d.'.$field.'),0) AS '.$field);
        }

        return $query;
    }
}
