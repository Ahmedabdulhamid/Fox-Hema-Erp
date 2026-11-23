<?php

namespace App\Modules\Reporting\Services;

use App\Filament\ReadModelCache;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Services\PosShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PosReadReport extends SalesReport
{
    protected string $accessClass = PosReportingAccess::class;

    public function read(User $actor, string $from, string $until, ?int $locationId = null, string $currency = 'EGP', ?int $cashierId = null, ?int $deviceId = null, ?int $sessionId = null, int $page = 1): array
    {
        $locations = $this->locations($actor, $from, $until, $locationId);
        $dates = TreasuryReadReport::dates($from, $until);
        $cashierId = app(PosReportingAccess::class)->cashier($actor, $cashierId);
        Validator::make(compact('currency', 'cashierId', 'deviceId', 'sessionId', 'page'), ['currency' => 'in:EGP', 'cashierId' => 'nullable|integer|min:1', 'deviceId' => 'nullable|integer|min:1', 'sessionId' => 'nullable|integer|min:1', 'page' => 'integer|min:1'])->validate();
        $sessions = PosSession::whereIn('location_id', $locations)->when($cashierId !== null, fn ($q) => $q->where('opened_by', $cashierId))
            ->when($deviceId !== null, fn ($q) => $q->where('pos_device_id', $deviceId))->when($sessionId !== null, fn ($q) => $q->whereKey($sessionId));
        abort_if(($sessionId !== null || $deviceId !== null || $cashierId !== null) && ! (clone $sessions)->exists() && $cashierId !== (int) $actor->id, 403);
        if ($deviceId !== null || $sessionId !== null) {
            abort_unless((clone $sessions)->exists(), 403);
        }
        $deps = [];
        foreach ($locations as $id) {
            $deps[] = CashReportingInvalidation::currentEpoch('pos', $id);
            foreach ($dates as $date) {
                $deps[] = DailyProjectionState::epoch('pos', $id, $date);
            }
        }
        $data = app(ReadModelCache::class)->remember('reporting_pos', [$locations, $from, $until, $currency, $cashierId, $deviceId, $sessionId, $page], function () use ($locations, $from, $until, $currency, $cashierId, $deviceId, $sessionId, $page, $sessions, $dates): array {
            $base = DB::table('reporting_pos_daily')->whereIn('reporting_pos_daily.location_id', $locations)->whereBetween('reporting_pos_daily.report_date', [$from, $until])->where('reporting_pos_daily.currency', $currency)
                ->when($cashierId !== null, fn ($q) => $q->where('cashier_id', $cashierId))->when($deviceId !== null, fn ($q) => $q->where('reporting_pos_daily.device_id', $deviceId))->when($sessionId !== null, fn ($q) => $q->where('reporting_pos_daily.session_id', $sessionId));
            $totals = $this->aggregate(clone $base)->first();
            $dailyRows = (clone $base)->selectRaw('report_date,CASE WHEN SUM(missing_sale)>0 THEN NULL ELSE SUM(sale_total-credit_total) END AS net')->groupBy('report_date')->get()->keyBy('report_date');
            $daily = array_map(fn (string $date): array => ['date' => $date, 'net' => $dailyRows->has($date) ? $dailyRows->get($date)->net : '0.0000'], $dates);
            $performance = $this->aggregate((clone $base)->join('pos_sessions as s', 's.id', '=', 'session_id')->join('pos_devices as d', 'd.id', '=', 'device_id')->join('users as u', 'u.id', '=', 'cashier_id'))
                ->addSelect('session_id', 'cashier_id', 'device_id', 's.session_number', 'd.name as device', 'u.name as cashier')
                ->groupBy('session_id', 'cashier_id', 'device_id', 's.session_number', 'd.name', 'u.name')->orderByDesc('sale_total')->orderBy('session_id')->paginate(25, ['*'], 'page', $page);
            $shiftRows = (clone $sessions)->where('opened_at', '<', CarbonImmutable::parse($until)->addDay()->toDateString().' 00:00:00')
                ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>=', $from.' 00:00:00'))->orderByDesc('id')
                ->paginate(25, ['id', 'session_number', 'pos_device_id', 'opened_by', 'status', 'opened_at', 'closed_at', 'opening_cash', 'expected_cash', 'counted_cash', 'cash_difference'], 'page', $page);
            $requests = DB::table('pos_transactions')->whereIn('location_id', $locations)
                ->whereRaw('COALESCE(server_received_at,created_at) >= ? AND COALESCE(server_received_at,created_at) < ?', [$from.' 00:00:00', CarbonImmutable::parse($until)->addDay()->toDateString().' 00:00:00'])
                ->when($cashierId !== null, fn ($q) => $q->where('cashier_id', $cashierId))->when($deviceId !== null, fn ($q) => $q->where('pos_device_id', $deviceId))->when($sessionId !== null, fn ($q) => $q->where('pos_session_id', $sessionId))
                ->orderByDesc('id')->paginate(25, ['id', 'pos_device_id', 'pos_session_id', 'cashier_id', 'transaction_type', 'sync_status', 'server_received_at', 'processed_at', 'attempts', 'error_code'], 'page', $page);

            return ['totals' => $this->visible((array) $totals), 'daily' => $daily, 'rows' => $performance->getCollection()->map(fn ($row): array => $this->visible((array) $row))->all(), 'total' => $performance->total(),
                'shifts' => $shiftRows->getCollection()->map(fn ($row): array => $row->attributesToArray())->all(), 'shift_total' => $shiftRows->total(),
                'requests' => $requests->getCollection()->map(fn ($row): array => (array) $row)->all(), 'request_total' => $requests->total()];
        }, config('read_cache.report_ttl'), dependencies: $deps);
        $live = null;
        if ($sessionId !== null) {
            $session = (clone $sessions)->firstOrFail();
            if ($session->status === 'open') {
                $scope = getPermissionsTeamId();
                try {
                    app(LocationContext::class)->setFor($actor, (int) $session->location_id);
                    $live = app(PosShiftService::class)->expectedClosingCash($actor, $sessionId);
                } catch (ValidationException) {
                    $live = null;
                } finally {
                    app(LocationContext::class)->setFor($actor, $scope === null ? null : (int) $scope);
                }
            }
        }

        return $data + ['page' => $page, 'live_expected' => $live, 'freshness' => app(DailyProjectionState::class)->freshness('pos', $locations, $from, $until)];
    }

    private function aggregate(Builder $query): Builder
    {
        foreach (PosReportingEvidence::FIELDS as $field) {
            $query->selectRaw('COALESCE(SUM(reporting_pos_daily.'.$field.'),0) AS '.$field);
        }

        return $query;
    }

    /** @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function visible(array $row): array
    {
        if ((int) $row['missing_close'] > 0) {
            foreach (['expected_cash', 'counted_cash', 'variance'] as $field) {
                $row[$field] = null;
            }
        }
        if ((int) $row['missing_sale'] > 0) {
            $row['sale_total'] = null;
            $row['sale_revenue'] = null;
        }

        return $row;
    }
}
