<?php

namespace App\Modules\Reporting\Services;

use App\Filament\ReadModelCache;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TreasuryReadReport
{
    /** @return list<string> */
    public static function dates(string $from, string $until): array
    {
        Validator::make(compact('from', 'until'), ['from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from|before_or_equal:today'])->validate();
        abort_if(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($until)) > 365, 422);
        $dates = [];
        for ($date = CarbonImmutable::parse($from); $date->toDateString() <= $until; $date = $date->addDay()) {
            $dates[] = $date->toDateString();
        }

        return $dates;
    }

    /** @return array<string,mixed> */
    public function read(User $actor, string $from, string $until, ?int $locationId = null, ?int $accountId = null, string $currency = 'EGP', int $page = 1): array
    {
        $accounts = app(TreasuryReportingAccess::class)->accounts($actor, $locationId, $accountId);
        $posLocations = array_values(array_filter(
            array_map('intval', array_keys(app(TreasuryReportingAccess::class)->branches($actor, $locationId))),
            fn (int $id): bool => $id > 0,
        ));
        $dates = self::dates($from, $until);
        Validator::make(compact('currency', 'page'), ['currency' => 'required|regex:/^[A-Z]{3}$/D', 'page' => 'integer|min:1'])->validate();
        $balances = app(TreasuryWorkspace::class)->allows($actor, TreasuryPermissions::BALANCES, getPermissionsTeamId());
        $ids = array_keys($accounts);
        $deps = [];
        foreach ($ids as $id) {
            $deps[] = CashReportingInvalidation::currentEpoch('treasury', $id);
            foreach ($dates as $date) {
                $deps[] = DailyProjectionState::epoch('treasury', $id, $date);
            }
        }
        foreach ($posLocations as $id) {
            $deps[] = CashReportingInvalidation::currentEpoch('pos', $id);
        }
        $data = app(ReadModelCache::class)->remember('reporting_treasury', [$ids, $posLocations, $from, $until, $currency, $balances, $page], function () use ($actor, $ids, $posLocations, $from, $until, $currency, $page, $dates): array {
            $base = DB::table('reporting_treasury_daily')->whereIn('account_id', $ids)->whereBetween('report_date', [$from, $until])->where('currency', $currency);
            $totals = (clone $base)->selectRaw("COALESCE(SUM(CASE WHEN category NOT IN ('internal','opening') AND direction = 'in' THEN amount ELSE 0 END),0) AS external_in,
                COALESCE(SUM(CASE WHEN category NOT IN ('internal','opening') AND direction = 'out' THEN amount ELSE 0 END),0) AS external_out,
                COALESCE(SUM(CASE WHEN category = 'internal' AND direction = 'in' THEN amount ELSE 0 END),0) AS internal_in,
                COALESCE(SUM(CASE WHEN category = 'internal' AND direction = 'out' THEN amount ELSE 0 END),0) AS internal_out,
                COALESCE(SUM(CASE WHEN category = 'opening' AND direction = 'in' THEN amount ELSE 0 END),0) AS opening_in,
                COALESCE(SUM(CASE WHEN category = 'opening' AND direction = 'out' THEN amount ELSE 0 END),0) AS opening_out")->first();
            $days = (clone $base)->whereNotIn('category', ['internal', 'opening'])->selectRaw("report_date,SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) AS net")->groupBy('report_date')->get()->keyBy('report_date');
            $daily = array_map(fn (string $date): array => ['date' => $date, 'net' => (string) ($days->get($date)->net ?? '0.0000')], $dates);
            $categories = (clone $base)->selectRaw('category,direction,SUM(amount) AS amount,SUM(entry_count) AS entries,SUM(reversal_count) AS reversals')->groupBy('category', 'direction')->orderBy('category')->get()->map(fn ($r): array => (array) $r)->all();
            $movements = DB::query()->fromSub(app(TreasuryReportingEvidence::class)->entries(), 'e')->join('treasury_accounts as a', 'a.id', '=', 'e.account_id')
                ->whereIn('e.account_id', $ids)->whereBetween('e.effective_date', [$from, $until])->where('e.currency', $currency)->select('e.*', 'a.name as account')
                ->orderByDesc('e.id')->paginate(25, ['*'], 'page', $page);
            $accountBalances = app(TreasuryWorkspace::class)->accountsWithBalances($actor)->whereIn('treasury_accounts.id', $ids)->where('treasury_accounts.currency', $currency)->orderBy('treasury_accounts.id')
                ->paginate(25, ['*'], 'page', $page);

            return ['totals' => (array) $totals, 'daily' => $daily, 'categories' => $categories,
                'pos_collections' => app(PosCollectionReport::class)->summarize($posLocations, $from, $until, $currency),
                'rows' => $movements->getCollection()->map(fn ($r): array => (array) $r)->all(), 'total' => $movements->total(),
                'balances' => $accountBalances->getCollection()->map(fn ($r): array => ['id' => $r->id, 'name' => $r->name, 'balance' => $r->ledger_balance])->all(), 'balance_total' => $accountBalances->total()];
        }, config('read_cache.report_ttl'), dependencies: $deps);
        $data['freshness'] = app(DailyProjectionState::class)->freshness('treasury', $ids, $from, $until);

        return $data + ['can_balance' => $balances, 'page' => $page, 'accounts' => $accounts];
    }
}
