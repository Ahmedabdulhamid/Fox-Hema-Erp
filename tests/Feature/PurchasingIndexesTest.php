<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PurchasingIndexesTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        foreach (['Products', 'Inventory', 'Purchasing'] as $module) {
            $this->app->make('migrator')->path(app_path("Modules/{$module}/Database/Migrations"));
        }
    }

    public function test_indexes_cover_document_filters_and_reverse_matching_lookups(): void
    {
        $expectedIndexes = [
            'suppliers' => [
                'idx_suppliers_active_name' => ['is_active', 'name'],
            ],
            'purchase_orders' => [
                'idx_po_location_status_date' => ['location_id', 'status', 'order_date'],
                'idx_po_supplier_date' => ['supplier_id', 'order_date'],
                'idx_po_status_expected_date' => ['status', 'expected_date'],
            ],
            'purchase_receipts' => [
                'idx_receipts_location_status_date' => ['location_id', 'status', 'received_at'],
                'idx_receipts_order_status' => ['purchase_order_id', 'status'],
            ],
            'vendor_bills' => [
                'idx_bills_status_due_date' => ['status', 'due_date'],
                'idx_bills_supplier_status_date' => ['supplier_id', 'status', 'bill_date'],
            ],
            'vendor_payments' => [
                'idx_payments_supplier_status_date' => ['supplier_id', 'status', 'payment_date'],
                'idx_payments_status_date' => ['status', 'payment_date'],
            ],
            'vendor_payment_allocations' => [
                'idx_allocations_bill_payment' => ['vendor_bill_id', 'vendor_payment_id'],
            ],
            'vendor_bill_receipt_matches' => [
                'idx_matches_receipt_bill_item' => ['purchase_receipt_item_id', 'vendor_bill_item_id'],
            ],
        ];

        foreach ($expectedIndexes as $table => $indexes) {
            $actualIndexes = collect(Schema::getIndexes($table))->keyBy('name');

            foreach ($indexes as $name => $columns) {
                $this->assertTrue($actualIndexes->has($name), "Missing index {$table}.{$name}");
                $this->assertSame($columns, $actualIndexes[$name]['columns']);
                $this->assertFalse($actualIndexes[$name]['unique']);
            }
        }
    }

    public function test_rollback_preserves_existing_indexes_constraints_and_data(): void
    {
        $migration = require app_path('Modules/Purchasing/Database/Migrations/2026_10_10_110515_add_lookup_indexes_to_purchasing_tables.php');
        $supplierId = DB::table('suppliers')->insertGetId(['code' => 'RETAINED-'.Str::uuid(), 'name' => 'Retained supplier']);
        $tables = [
            'suppliers', 'purchase_orders', 'purchase_receipts', 'vendor_bills',
            'vendor_payments', 'vendor_payment_allocations', 'vendor_bill_receipt_matches',
        ];

        $migration->down();
        $originalIndexes = [];
        $originalForeignKeys = [];
        foreach ($tables as $table) {
            $originalIndexes[$table] = Schema::getIndexes($table);
            $originalForeignKeys[$table] = Schema::getForeignKeys($table);
        }

        $this->assertTrue(Schema::hasIndex('purchase_orders', ['supplier_id', 'status']));
        $this->assertTrue(Schema::hasIndex('purchase_orders', ['order_number'], 'unique'));
        $this->assertTrue(Schema::hasIndex('vendor_bills', ['supplier_id', 'bill_number'], 'unique'));
        $this->assertTrue(Schema::hasIndex('vendor_bills', ['supplier_id', 'due_date']));
        $this->assertTrue(Schema::hasIndex('vendor_payment_allocations', ['vendor_payment_id', 'vendor_bill_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex('vendor_bill_receipt_matches', ['vendor_bill_item_id', 'purchase_receipt_item_id'], 'unique'));

        $migration->up();
        $migration->down();

        foreach ($tables as $table) {
            $this->assertSame($originalIndexes[$table], Schema::getIndexes($table));
            $this->assertSame($originalForeignKeys[$table], Schema::getForeignKeys($table));
        }
        $this->assertDatabaseHas('suppliers', ['id' => $supplierId, 'name' => 'Retained supplier']);
        $migration->up();
    }
}
