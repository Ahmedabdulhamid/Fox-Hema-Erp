<?php

namespace App\Modules\Reporting\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class InventoryProjection
{
    public static function epoch(int $locationId, string $date): string
    {
        return DailyProjectionState::epoch('inventory', $locationId, $date);
    }

    public function request(int $locationId, string $date): void
    {
        app(DailyProjectionState::class)->request('inventory', $locationId, $date);
    }

    public function project(int $locationId, string $date): bool
    {
        return app(DailyProjectionState::class)->project('inventory', $locationId, $date, function () use ($locationId, $date): void {
            foreach ($this->rows($locationId, $date) as $table => $rows) {
                DB::table($table)->where('location_id', $locationId)->where('report_date', $date)->delete();
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table($table)->insert(array_map(fn (array $row): array => $row + ['location_id' => $locationId, 'report_date' => $date, 'projected_at' => now()], $chunk));
                }
            }
        });
    }

    public function requestIfChanged(int $locationId, string $date): void
    {
        DB::transaction(function () use ($locationId, $date): void {
            $partition = DB::table('reporting_inventory_partitions')->where('location_id', $locationId)->where('report_date', $date)->lockForUpdate()->first();
            if ($partition === null) {
                $this->request($locationId, $date);

                return;
            }
            if ($partition->revision !== $partition->projected_revision) {
                return;
            }
            foreach ($this->rows($locationId, $date) as $table => $expected) {
                $stored = DB::table($table)->where('location_id', $locationId)->where('report_date', $date)->get()->map(fn ($row): array => (array) $row)->all();
                if ($this->canonical($expected, $table) !== $this->canonical($stored, $table)) {
                    $this->request($locationId, $date);

                    return;
                }
            }
        }, 3);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function rows(int $locationId, string $date): array
    {
        $evidence = app(InventoryReportingEvidence::class);

        return [
            'reporting_inventory_daily' => $evidence->closings($locationId, $date)->orderBy('v.variant_id')->get()->map(fn ($row): array => (array) $row)->all(),
            'reporting_inventory_movement_daily' => $evidence->movements($locationId, $date)->get()->map(fn ($row): array => (array) $row)->all(),
        ];
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function canonical(array $rows, string $table): array
    {
        $movements = $table === 'reporting_inventory_movement_daily';
        $fields = $movements ? ['received_quantity', 'issued_quantity', 'movement_count', 'reversal_count'] : ['closing_quantity', 'closing_value'];
        $result = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($fields as $field) {
                $values[$field] = $row[$field] === null ? null : (string) BigDecimal::of((string) $row[$field])->toScale(4);
            }
            $result[$row['variant_id'].($movements ? ':'.$row['type'] : '')] = $values;
        }
        ksort($result);

        return $result;
    }
}
