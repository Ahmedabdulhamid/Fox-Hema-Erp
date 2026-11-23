<?php

namespace App\Modules\Reporting\Services;

use App\Filament\ReadModelCache;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryReportingInvalidation
{
    private bool $processing = false;

    private const TABLES = ['stock_balances', 'stock_movements', 'inventory_stock_policies', 'inventory_cost_states', 'inventory_cost_entries', 'fifo_layers', 'fifo_layer_consumptions'];

    public static function currentEpoch(int $locationId): string
    {
        return 'reporting-inventory-current:'.$locationId;
    }

    /**
     * Observe SQL writes as well as Eloquent: reservations, upserts and FIFO persistence use builders.
     * Epochs and dirty revisions use the source connection's transaction, so rollback hides both.
     * Unscoped bulk writes conservatively invalidate all existing inventory locations.
     */
    public function written(QueryExecuted $event): void
    {
        if ($this->processing || $event->connection !== DB::connection()
            || ! preg_match('/^\s*(?:insert(?: ignore)? into|update|delete from)\s+[`"]?([a-z_]+)/i', $event->sql, $match)
            || ! in_array($match[1], self::TABLES, true)) {
            return;
        }
        $this->processing = true;
        try {
            if (! Schema::hasTable('reporting_inventory_partitions')) {
                return;
            }
            $table = $match[1];
            $locations = $this->locations($event, $table);
            foreach ($locations as $locationId) {
                app(ReadModelCache::class)->invalidate(self::currentEpoch($locationId), $event->connection);
                $setClause = preg_split('/\bwhere\b/i', $event->sql)[0];
                $reservationOnly = $table === 'stock_balances' && preg_match('/^\s*update\b/i', $event->sql)
                    && ! str_contains($setClause, 'quantity_on_hand');
                if ($table === 'inventory_stock_policies' || $reservationOnly) {
                    continue;
                }
                DB::table('reporting_inventory_partitions')->where('location_id', $locationId)->update([
                    'revision' => DB::raw('revision + 1'), 'requested_at' => now(),
                ]);
                app(InventoryProjection::class)->request($locationId, today()->toDateString());
            }
        } finally {
            $this->processing = false;
        }
    }

    /** @return list<int> */
    private function locations(QueryExecuted $event, string $table): array
    {
        $fields = [];
        if (preg_match('/^\s*insert(?: ignore)? into\s+[`"]?[a-z_]+[`"]?\s*\(([^)]+)\)/i', $event->sql, $columns)) {
            $names = array_map(fn (string $name): string => trim($name, ' `"'), explode(',', $columns[1]));
            foreach (array_chunk($event->bindings, count($names)) as $values) {
                if (count($values) === count($names)) {
                    foreach (array_combine($names, $values) as $name => $value) {
                        $fields[$name][] = $value;
                    }
                }
            }
        } else {
            $raw = $event->toRawSql();
            $where = stripos($raw, ' where ');
            if ($where !== false && ! preg_match('/\bor\b/i', substr($raw, $where))) {
                preg_match_all('/(?<![a-z0-9_])(?:[`"]?[a-z_]+[`"]?\.)?[`"]?(location_id|variant_id|id|stock_movement_id|cost_entry_id|fifo_layer_id)[`"]?\s*=\s*[\x27"]?(\d+)/i', substr($raw, $where), $matches, PREG_SET_ORDER);
                foreach ($matches as $item) {
                    $fields[$item[1]][] = (int) $item[2];
                }
            }
        }
        if (isset($fields['location_id'])) {
            $locations = array_values(array_unique(array_filter(array_map('intval', $fields['location_id']), fn (int $id): bool => $id > 0)));
            if ($locations !== []) {
                return $locations;
            }
        }
        $query = DB::table($table);
        $bounded = false;
        if (isset($fields['variant_id']) && in_array($table, ['stock_balances', 'stock_movements', 'inventory_stock_policies', 'inventory_cost_states', 'fifo_layers'], true)) {
            $query->whereIn('variant_id', $fields['variant_id']);
            $bounded = true;
        }
        foreach (['id', 'stock_movement_id', 'cost_entry_id', 'fifo_layer_id'] as $field) {
            if (isset($fields[$field])) {
                $query->whereIn($field, $fields[$field]);
                $bounded = true;
            }
        }
        if ($bounded) {
            if (in_array($table, ['stock_balances', 'stock_movements', 'inventory_stock_policies', 'inventory_cost_states'], true)) {
                $locations = $query->whereNotNull('location_id')->pluck('location_id')->all();
            } else {
                $movementIds = match ($table) {
                    'inventory_cost_entries','fifo_layers' => $query->pluck('stock_movement_id')->all(),
                    default => DB::table('inventory_cost_entries')->whereIn('id', $query->select('cost_entry_id'))->pluck('stock_movement_id')->all(),
                };
                $locations = DB::table('stock_movements')->whereIn('id', $movementIds)->pluck('location_id')->all();
            }
            if ($locations !== []) {
                return array_values(array_unique(array_map('intval', $locations)));
            }
        }

        return DB::table('stock_balances')->select('location_id')->union(DB::table('reporting_inventory_partitions')->select('location_id'))
            ->pluck('location_id')->map(fn ($id): int => (int) $id)->all();
    }
}
