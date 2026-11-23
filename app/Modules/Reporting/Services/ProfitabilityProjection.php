<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Sales\Services\ProfitabilityReport;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class ProfitabilityProjection
{
    public const AMOUNTS = ['revenue', 'known_cost', 'return_quantity', 'return_revenue', 'return_loss', 'return_variance', 'credit_adjustment'];

    public static function epoch(int $locationId, string $date): string
    {
        return DailyProjectionState::epoch('profitability', $locationId, $date);
    }

    public function request(int $locationId, string $date): void
    {
        app(DailyProjectionState::class)->request('profitability', $locationId, $date);
    }

    public function requestIfChanged(int $locationId, string $date): void
    {
        DB::transaction(function () use ($locationId, $date): void {
            $partition = DB::table('reporting_profitability_partitions')->where('location_id', $locationId)
                ->where('report_date', $date)->lockForUpdate()->first();
            if ($partition === null) {
                $this->request($locationId, $date);

                return;
            }
            if ($partition->revision !== $partition->projected_revision) {
                return;
            }
            $stored = DB::table('reporting_profitability_daily')->where('location_id', $locationId)->where('report_date', $date)->get()->map(fn ($row): array => (array) $row)->all();
            if ($this->comparable($stored) !== $this->comparable($this->rows($locationId, $date))) {
                $this->request($locationId, $date);
            }
        }, 3);
    }

    public function project(int $locationId, string $date): bool
    {
        return app(DailyProjectionState::class)->project('profitability', $locationId, $date, function () use ($locationId, $date): void {
            $rows = array_map(fn (array $row): array => $row + ['projected_at' => now()], $this->rows($locationId, $date));
            DB::table('reporting_profitability_daily')->where('location_id', $locationId)->where('report_date', $date)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('reporting_profitability_daily')->insert($chunk);
            }
        });
    }

    /** @return list<array<string, int|string>> */
    private function rows(int $locationId, string $date): array
    {
        $events = app(ProfitabilityReport::class)->projectionEvents($date, $date, $locationId);

        return $events->selectRaw("variant_id, currency, SUM(revenue) AS revenue, COALESCE(SUM(cost), 0) AS known_cost, SUM(missing) AS missing,
            SUM(CASE WHEN event_type = 'return' THEN quantity ELSE 0 END) AS return_quantity,
            SUM(CASE WHEN event_type = 'return' THEN -revenue ELSE 0 END) AS return_revenue,
            COALESCE(SUM(return_loss), 0) AS return_loss, COALESCE(SUM(return_variance), 0) AS return_variance,
            SUM(CASE WHEN event_type = 'credit' THEN revenue ELSE 0 END) AS credit_adjustment")
            ->groupBy('variant_id', 'currency')->orderBy('variant_id')->orderBy('currency')->get()
            ->map(function ($row) use ($locationId, $date): array {
                $result = ['location_id' => $locationId, 'report_date' => $date, 'variant_id' => (int) $row->variant_id,
                    'currency' => $row->currency, 'missing' => (int) $row->missing];
                foreach (self::AMOUNTS as $field) {
                    $result[$field] = (string) BigDecimal::of((string) $row->{$field})->toScale(4);
                }

                return $result;
            })->all();
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<string, array<string, int|string>>
     */
    private function comparable(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $values = ['missing' => (int) $row['missing']];
            foreach (self::AMOUNTS as $field) {
                $values[$field] = (string) BigDecimal::of((string) $row[$field])->toScale(4);
            }
            $result[$row['variant_id'].':'.$row['currency']] = $values;
        }
        ksort($result);

        return $result;
    }
}
