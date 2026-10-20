<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('purchasing:audit-valuation {--details : Include original legacy row values and physical balances for review}')]
#[Description('Read-only Phase 3 schema, legacy valuation and receiving audit; never reconciles records')]
class AuditPurchasingValuation extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();
        if ($database !== 'fox_hema_pos' && ! (app()->environment('testing')
            && ($database === ':memory:' || str_starts_with($database, 'codex_public_ids_test_purchasing_')))) {
            $this->error('Audit is restricted to fox_hema_pos or a dedicated purchasing test database.');

            return self::FAILURE;
        }
        $required = ['inventory_cost_states', 'stock_balances', 'inventory_standard_costs',
            'inventory_standard_cost_revisions', 'inventory_cost_entries', 'purchase_receipts', 'purchase_receipt_items'];
        foreach ($required as $table) {
            if (! Schema::hasTable($table)) {
                $this->error('Missing required table: '.$table);

                return self::FAILURE;
            }
        }
        $scoped = Schema::hasColumn('inventory_cost_states', 'location_id');
        $legacy = DB::table('inventory_cost_states');
        if ($scoped) {
            $legacy->whereNull('location_id');
        }
        $legacyCount = $legacy->count();
        $duplicates = DB::table('purchase_receipt_items')->select('purchase_receipt_id', 'purchase_order_item_id')
            ->groupBy('purchase_receipt_id', 'purchase_order_item_id')->havingRaw('COUNT(*) > 1')->get()->count();
        $destinations = DB::table('purchase_receipts')->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_receipts.purchase_order_id')
            ->whereColumn('purchase_receipts.location_id', '!=', 'purchase_orders.location_id')->count();
        $missingRevisions = DB::table('inventory_standard_costs')->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('inventory_standard_cost_revisions')
                ->whereColumn('standard_cost_id', 'inventory_standard_costs.id')->where('effective_at', '<=', now());
        })->count();
        $ambiguous = DB::table('inventory_standard_cost_revisions')->select('standard_cost_id', 'effective_at')
            ->groupBy('standard_cost_id', 'effective_at')->havingRaw('COUNT(DISTINCT new_cost) > 1')->get()->count();
        $unvalued = DB::table('stock_balances')->where('quantity_on_hand', '!=', 0);
        if ($scoped) {
            $unvalued->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('inventory_cost_states')
                    ->whereColumn('inventory_cost_states.variant_id', 'stock_balances.variant_id')
                    ->whereColumn('inventory_cost_states.location_id', 'stock_balances.location_id');
            });
        }
        $unvaluedCount = $unvalued->count();
        $missingValuation = DB::table('purchase_receipt_items as items')
            ->join('purchase_receipts as receipts', 'receipts.id', '=', 'items.purchase_receipt_id')
            ->leftJoin('inventory_cost_entries as entries', 'entries.stock_movement_id', '=', 'items.stock_movement_id')
            ->where('receipts.status', 'posted')->where('items.quantity', '>', 0)->whereNull('entries.id')->count();
        $drift = 0;
        if ($scoped) {
            $drift = DB::table('inventory_cost_states as states')->leftJoin('stock_balances as balances', function ($join): void {
                $join->on('states.variant_id', '=', 'balances.variant_id')->on('states.location_id', '=', 'balances.location_id');
            })->whereNotNull('states.location_id')->whereRaw('states.on_hand_qty <> COALESCE(balances.quantity_on_hand, 0)')->count();
        }
        $this->table(['Check', 'Count / state'], [
            ['Location-scoped schema', $scoped ? 'present' : 'pending'],
            ['Unresolved legacy aggregates (posting blocked per variant)', $legacyCount],
            ['Duplicate receipt lines (migration blocker)', $duplicates],
            ['Receipt destination mismatches (migration blocker)', $destinations],
            ['Standard costs without effective revisions', $missingRevisions],
            ['Conflicting revision timestamps', $ambiguous],
            ['Nonzero stock without a location cost state', $unvaluedCount],
            ['Posted receipt lines without valuation', $missingValuation],
            ['Physical / valued quantity mismatches', $drift],
        ]);
        if ($this->option('details')) {
            $this->table(['Legacy state ID', 'Variant', 'Quantity', 'Value', 'Average cost'],
                $legacy->orderBy('id')->get()->map(fn ($row): array => [$row->id, $row->variant_id, $row->on_hand_qty, $row->total_value, $row->avg_cost]));
            $this->table(['Balance ID', 'Variant', 'Location', 'On hand', 'Reserved'],
                DB::table('stock_balances')->orderBy('variant_id')->orderBy('location_id')->get()
                    ->map(fn ($row): array => [$row->id, $row->variant_id, $row->location_id, $row->quantity_on_hand, $row->quantity_reserved]));
        }
        $this->line('Read-only. Stock and value allocation requires a separately approved evidence-based reconciliation.');

        return $legacyCount + $duplicates + $destinations + $missingRevisions + $ambiguous + $unvaluedCount + $missingValuation + $drift > 0 ? self::FAILURE : self::SUCCESS;
    }
}
