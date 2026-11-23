<?php

namespace App\Modules\Reporting\Services;

use App\Filament\ReadModelCache;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

class DailyProjectionState
{
    private function scopeColumn(string $domain): string
    {
        return $domain === 'treasury' ? 'account_id' : 'location_id';
    }

    public static function epoch(string $domain, int $locationId, string $date): string
    {
        return 'reporting-'.$domain.':'.$locationId.':'.$date;
    }

    public function request(string $domain, int $locationId, string $date): void
    {
        DB::table('reporting_'.$domain.'_partitions')->upsert([
            $this->scopeColumn($domain) => $locationId, 'report_date' => $date, 'revision' => 1,
            'projected_revision' => 0, 'requested_at' => now(),
        ], [$this->scopeColumn($domain), 'report_date'], ['revision' => DB::raw('revision + 1'), 'requested_at']);
    }

    public function project(string $domain, int $locationId, string $date, Closure $rebuild): bool
    {
        return DB::transaction(function () use ($domain, $locationId, $date, $rebuild): bool {
            $table = 'reporting_'.$domain.'_partitions';
            $partition = DB::table($table)->where($this->scopeColumn($domain), $locationId)->where('report_date', $date)->lockForUpdate()->first();
            if ($partition === null || $partition->revision === $partition->projected_revision) {
                return false;
            }
            $rebuild();
            DB::table($table)->where('id', $partition->id)->update([
                'projected_revision' => $partition->revision, 'projected_at' => now(), 'dispatched_at' => null,
            ]);
            app(ReadModelCache::class)->invalidate(self::epoch($domain, $locationId, $date));

            return true;
        }, 3);
    }

    /** @param list<int> $locations
     * @return array{projected_days: int, expected_days: int, pending_days: int, oldest_projection: ?string}
     */
    public function freshness(string $domain, array $locations, string $from, string $until): array
    {
        $row = DB::table('reporting_'.$domain.'_partitions')->whereIn($this->scopeColumn($domain), $locations)->whereBetween('report_date', [$from, $until])
            ->selectRaw('COUNT(projected_at) AS projected_days, MIN(projected_at) AS oldest_projection,
                COALESCE(SUM(CASE WHEN revision > projected_revision THEN 1 ELSE 0 END), 0) AS pending_days')->first();

        return ['projected_days' => (int) $row->projected_days,
            'expected_days' => (int) (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($until)) + 1) * count($locations),
            'pending_days' => (int) $row->pending_days, 'oldest_projection' => $row->oldest_projection];
    }
}
