<?php

namespace App\Modules\Reporting\Services;

use App\Filament\ReadModelCache;
use App\Modules\AccessManagement\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StockTransferReadReport extends SalesReport
{
    protected string $accessClass = StockTransferReportingAccess::class;

    public function read(User $actor, string $from, string $until, ?int $locationId = null, string $currency = 'EGP', ?int $destinationId = null, ?int $variantId = null, int $page = 1): array
    {
        $sources = $this->locations($actor, $from, $until, $locationId);
        $allowed = array_keys(app(StockTransferReportingAccess::class)->branches($actor));
        Validator::make(compact('destinationId', 'variantId', 'page'), [
            'destinationId' => 'nullable|integer|min:1', 'variantId' => 'nullable|integer|min:1', 'page' => 'integer|min:1',
        ], [], ['destinationId' => __('stock_transfer_reporting.destination'), 'variantId' => __('reporting.filters.variant')])->validate();
        abort_if($destinationId !== null && ! in_array($destinationId, $allowed, true), 403);
        $destinations = $destinationId === null ? $allowed : [$destinationId];
        $dates = TreasuryReadReport::dates($from, $until);
        $dependencies = ['catalog'];
        foreach ($sources as $id) {
            $dependencies[] = 'reporting-stock-transfer-current:'.$id;
            foreach ($dates as $date) {
                $dependencies[] = DailyProjectionState::epoch('stock_transfer', $id, $date);
            }
        }
        $data = app(ReadModelCache::class)->remember('reporting_stock_transfer', [$sources, $destinations, $from, $until, $variantId, $page],
            function () use ($sources, $destinations, $from, $until, $variantId, $page, $dates): array {
                $base = DB::table('reporting_stock_transfer_daily as d')->whereIn('d.location_id', $sources)->whereIn('d.to_location_id', $destinations)
                    ->whereBetween('d.report_date', [$from, $until]);
                $lineBase = (clone $base)->when($variantId !== null, fn ($q) => $q->where('d.variant_id', $variantId));
                $events = $this->aggregate(clone $base)->addSelect('d.event')->groupBy('d.event')->get()->keyBy('event');
                $totals = [];
                foreach (['transfer', 'request_line', 'shipment', 'dispatch_line', 'receipt', 'receipt_line'] as $event) {
                    $totals[$event] = isset($events[$event]) ? (array) $events[$event] : array_fill_keys(StockTransferReportingEvidence::FIELDS, '0.0000');
                }
                foreach (['shipment' => 'dispatch_line', 'receipt' => 'receipt_line'] as $header => $line) {
                    $totals[$line]['missing'] = (string) BigDecimal::of((string) $totals[$line]['missing'])->plus((string) $totals[$header]['missing']);
                }
                $trend = $this->aggregate((clone $base)->whereIn('d.event', ['transfer', 'request_line', 'shipment', 'dispatch_line', 'receipt', 'receipt_line']))
                    ->addSelect('d.report_date', 'd.event')->groupBy('d.report_date', 'd.event')->get()->groupBy('report_date');
                $coverage = DB::table('reporting_stock_transfer_partitions')->whereIn('location_id', $sources)->whereBetween('report_date', [$from, $until])
                    ->whereColumn('revision', 'projected_revision')->whereNotNull('projected_at')->selectRaw('report_date,COUNT(*) AS coverage')->groupBy('report_date')->pluck('coverage', 'report_date');
                $daily = [];
                foreach ($dates as $date) {
                    $day = ['date' => $date];
                    foreach (['transfer', 'request_line', 'dispatch_line', 'receipt_line'] as $event) {
                        $row = ($trend[$date] ?? collect())->firstWhere('event', $event);
                        $header = match ($event) {
                            'dispatch_line' => 'shipment', 'receipt_line' => 'receipt', default => $event
                        };
                        $headerRow = ($trend[$date] ?? collect())->firstWhere('event', $header);
                        $day[$event] = (int) ($coverage[$date] ?? 0) !== count($sources) || ($row->missing ?? 0) > 0 || ($headerRow->missing ?? 0) > 0
                            ? null : ($event === 'transfer' ? ($row->document_count ?? '0.0000') : ($row->quantity ?? '0.0000'));
                    }
                    $daily[] = $day;
                }
                $activity = $this->aggregate((clone $base)->where('d.variant_id', 0))->addSelect('d.report_date', 'd.event', 'd.status', 'd.location_id', 'd.to_location_id')
                    ->groupBy('d.report_date', 'd.event', 'd.status', 'd.location_id', 'd.to_location_id')
                    ->orderByDesc('d.report_date')->orderBy('d.location_id')->orderBy('d.to_location_id')->orderBy('d.event')->orderBy('d.status');
                $routes = (clone $base)->whereIn('d.event', ['request_line', 'dispatch_line', 'receipt_line'])
                    ->select('d.location_id', 'd.to_location_id')
                    ->selectRaw('SUM(CASE WHEN d.event="request_line" THEN d.quantity ELSE 0 END) AS requested,
                        SUM(CASE WHEN d.event="dispatch_line" THEN d.quantity ELSE 0 END) AS dispatched,
                        SUM(CASE WHEN d.event="receipt_line" THEN d.quantity ELSE 0 END) AS received,
                        SUM(CASE WHEN d.event="dispatch_line" THEN d.remaining ELSE 0 END) AS in_transit,
                        SUM(CASE WHEN d.event="request_line" THEN d.missing ELSE 0 END) AS requested_missing,
                        SUM(CASE WHEN d.event="dispatch_line" THEN d.missing ELSE 0 END) AS dispatched_missing,
                        SUM(CASE WHEN d.event="receipt_line" THEN d.missing ELSE 0 END) AS received_missing,
                        SUM(d.missing) AS missing')
                    ->groupBy('d.location_id', 'd.to_location_id')
                    ->orderByDesc('dispatched')->orderBy('d.location_id')->orderBy('d.to_location_id');
                $variants = $this->variantJoins($this->aggregate((clone $lineBase)->whereIn('d.event', ['request_line', 'dispatch_line', 'receipt_line'])))
                    ->addSelect('d.variant_id', 'd.event', 'brand.name as brand_name', 'c.name as color_name', 'c.hex_code as color_hex', 'z.name as size_name')
                    ->groupBy('d.variant_id', 'd.event', 'brand.name', 'c.name', 'c.hex_code', 'z.name')
                    ->orderByDesc('quantity')->orderBy('d.variant_id')->orderBy('d.event');
                $transit = DB::table('reporting_stock_transfer_transit as d')->whereIn('d.location_id', $sources)->whereIn('d.to_location_id', $destinations)
                    ->when($variantId !== null, fn ($q) => $q->where('d.variant_id', $variantId));
                $current = (array) (clone $transit)->selectRaw('COALESCE(SUM(remaining),0) AS remaining,COALESCE(SUM(missing),0) AS missing,
                    COALESCE(SUM(damaged),0) AS damaged,COALESCE(SUM(reversals),0) AS reversals,
                    COUNT(DISTINCT CASE WHEN remaining>0 THEN shipment_id END) AS awaiting')->first();
                if (DB::table('reporting_stock_transfer_daily')->whereIn('location_id', $sources)->whereIn('to_location_id', $destinations)
                    ->whereIn('event', ['shipment', 'receipt'])->where('missing', '>', 0)->exists()) {
                    $current['missing'] = (string) BigDecimal::of((string) $current['missing'])->plus(1);
                }
                $transitRows = $this->variantJoins((clone $transit)->where(fn ($q) => $q->where('d.remaining', '<>', 0)->orWhere('d.missing', '>', 0)->orWhere('d.damaged', '>', 0)))
                    ->join('stock_transfers as t', 't.id', '=', 'd.transfer_id')->join('stock_transfer_shipments as s', 's.id', '=', 'd.shipment_id')
                    ->select('d.*', 't.transfer_number', 's.shipment_number', 'brand.name as brand_name', 'c.name as color_name', 'c.hex_code as color_hex', 'z.name as size_name')
                    ->orderBy('d.dispatched_at')->orderBy('d.shipment_item_id');
                $exceptions = DB::table('stock_transfers as t')->whereIn('t.from_location_id', $sources)->whereIn('t.to_location_id', $destinations)
                    ->whereNotIn('t.status', ['received', 'closed', 'cancelled'])
                    ->select('t.id', 't.transfer_number', 't.status', 't.from_location_id as location_id', 't.to_location_id', 't.requested_at', 't.approved_at', 't.created_at')
                    ->orderByRaw('COALESCE(t.requested_at,t.created_at)')->orderBy('t.id');
                $documents = $this->documents($sources, $destinations, $from, $until);

                return ['totals' => $totals, 'daily' => $daily, 'current' => $current,
                    'rows' => $this->paginate($activity, $page), 'routes' => $this->paginate($routes, $page),
                    'variants' => $this->paginate($variants, $page), 'transit' => $this->paginate($transitRows, $page),
                    'documents' => $this->paginate($documents, $page),
                    'exceptions' => $this->paginate($exceptions, $page)];
            }, config('read_cache.report_ttl'), dependencies: $dependencies);
        $freshness = app(DailyProjectionState::class)->freshness('stock_transfer', $sources, $from, $until);
        $incomplete = $freshness['pending_days'] > 0 || $freshness['projected_days'] !== $freshness['expected_days'];
        $pending = DB::table('reporting_stock_transfer_partitions')->whereIn('location_id', $sources)->whereColumn('revision', '>', 'projected_revision')->exists();
        $hasCurrent = DB::table('reporting_stock_transfer_partitions')->whereIn('location_id', $sources)->whereNotNull('projected_at')->distinct()->count('location_id') === count($sources);
        foreach ($data['totals'] as &$total) {
            foreach (StockTransferReportingEvidence::FIELDS as $field) {
                if ($incomplete || ($total['missing'] > 0 && in_array($field, ['quantity', 'remaining', 'partial_count', 'complete_count', 'partial_dispatch_count', 'partial_receipt_count'], true))) {
                    if ($field !== 'missing') {
                        $total[$field] = null;
                    }
                }
            }
        }
        unset($total);
        foreach (['rows', 'variants'] as $collection) {
            foreach ($data[$collection]['items'] as &$row) {
                foreach (['quantity', 'remaining', 'partial_count', 'complete_count'] as $field) {
                    if ($row['missing'] > 0 || $incomplete) {
                        $row[$field] = null;
                    }
                }
            }
            unset($row);
        }
        foreach ($data['routes']['items'] as &$route) {
            foreach (['requested', 'dispatched', 'received', 'in_transit'] as $field) {
                $evidenceField = $field === 'in_transit' ? 'dispatched_missing' : $field.'_missing';
                if ($route[$evidenceField] > 0 || $incomplete) {
                    $route[$field] = null;
                }
            }
        }
        unset($route);
        $currentPending = $pending || ! $hasCurrent;
        if ($currentPending || $data['current']['missing'] > 0) {
            $data['current']['remaining'] = null;
        }
        if ($currentPending || $data['current']['missing'] > 0) {
            $data['current']['awaiting'] = null;
        }

        return $data + ['freshness' => $freshness, 'current_pending' => $currentPending,
            'locations' => app(StockTransferReportingAccess::class)->branches($actor),
            'location_types' => DB::table('locations')->whereIn('id', $allowed)->pluck('type', 'id')->all()];
    }

    private function aggregate(Builder $query): Builder
    {
        foreach (StockTransferReportingEvidence::FIELDS as $field) {
            $query->selectRaw('COALESCE(SUM(d.'.$field.'),0) AS '.$field);
        }

        return $query;
    }

    /** @param list<int> $sources
     * @param  list<int>  $destinations
     */
    private function documents(array $sources, array $destinations, string $from, string $until): Builder
    {
        $end = CarbonImmutable::parse($until)->addDay()->toDateString();
        $transfers = DB::table('stock_transfers as t')->whereIn('t.from_location_id', $sources)->whereIn('t.to_location_id', $destinations);
        $requests = (clone $transfers)->whereRaw('COALESCE(t.requested_at,t.created_at)>=? AND COALESCE(t.requested_at,t.created_at)<?', [$from, $end])
            ->selectRaw('t.id,"transfer" AS event,t.transfer_number AS document_number,t.transfer_number,t.from_location_id AS location_id,t.to_location_id,t.status,COALESCE(t.requested_at,t.created_at) AS activity_at');
        foreach (['stock_transfer_shipments' => ['shipment', 'shipment_number', 'dispatched_at'], 'stock_transfer_receipts' => ['receipt', 'receipt_number', 'posted_at']] as $table => [$event, $number, $timestamp]) {
            $requests->unionAll((clone $transfers)->join($table.' as document', 'document.stock_transfer_id', '=', 't.id')
                ->whereRaw('COALESCE(document.'.$timestamp.',document.created_at)>=? AND COALESCE(document.'.$timestamp.',document.created_at)<?', [$from, $end])
                ->selectRaw('document.id,? AS event,document.'.$number.' AS document_number,t.transfer_number,t.from_location_id AS location_id,t.to_location_id,document.status,COALESCE(document.'.$timestamp.',document.created_at) AS activity_at', [$event]));
        }

        return DB::query()->fromSub($requests, 'documents')->orderByDesc('activity_at')->orderBy('event')->orderBy('id');
    }

    private function variantJoins(Builder $query): Builder
    {
        return $query->leftJoin('product_variants as v', 'v.id', '=', 'd.variant_id')->leftJoin('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('brands as brand', 'brand.id', '=', 'p.brand_id')->leftJoin('colors as c', 'c.id', '=', 'v.color_id')->leftJoin('sizes as z', 'z.id', '=', 'v.size_id');
    }

    /** @return array{items: list<array<string,mixed>>,total:int} */
    private function paginate(Builder $query, int $page): array
    {
        $rows = $query->paginate(25, ['*'], 'page', $page);

        return ['items' => array_map(fn ($row): array => (array) $row, $rows->items()), 'total' => $rows->total()];
    }

    public function age(?string $date): ?int
    {
        return $date === null ? null : (int) CarbonImmutable::parse($date)->diffInDays(now(), false);
    }
}
