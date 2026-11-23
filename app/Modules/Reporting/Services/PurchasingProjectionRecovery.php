<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Jobs\ProjectPurchasingDay;
use Illuminate\Support\Facades\DB;

class PurchasingProjectionRecovery extends SalesProjectionRecovery
{
    protected string $domain = 'purchasing';

    protected string $projectionClass = PurchasingProjection::class;

    protected string $jobClass = ProjectPurchasingDay::class;

    public function scan(int $limit = 100): void
    {
        $limit = max(1, min(500, $limit));
        foreach (app(PurchasingReportingEvidence::class)->sources() as $source => $query) {
            DB::transaction(function () use ($source, $query, $limit): void {
                DB::table('reporting_purchasing_checkpoints')->insertOrIgnore(['source' => $source, 'last_id' => 0]);
                $checkpoint = DB::table('reporting_purchasing_checkpoints')->where('source', $source)->lockForUpdate()->first();
                $rows = DB::query()->fromSub($query, 'source')->where('id', '>', $checkpoint->last_id)->orderBy('id')->limit($limit)->get();
                foreach ($rows->filter(fn ($row): bool => $row->report_date !== null)
                    ->unique(fn ($row): string => $row->location_id.':'.$row->report_date) as $row) {
                    app(PurchasingProjection::class)->requestIfChanged((int) $row->location_id, substr($row->report_date, 0, 10));
                }
                DB::table('reporting_purchasing_checkpoints')->where('source', $source)
                    ->update(['last_id' => $rows->last()?->id ?? 0, 'scanned_at' => now()]);
            }, 3);
        }
    }
}
