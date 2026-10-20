<?php

namespace Tests\Feature;

use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorPayment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PurchasingReferenceKeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_indexes_preserve_rows_and_support_repeated_execution(): void
    {
        $tables = ['purchase_orders' => 'supplier_id', 'purchase_order_items' => 'purchase_order_id', 'purchase_receipts' => 'purchase_order_id', 'vendor_bills' => 'supplier_id', 'vendor_payments' => 'supplier_id'];
        $supplier = Supplier::factory()->create();
        $order = PurchaseOrder::factory()->create(['supplier_id' => $supplier->id]);
        PurchaseOrderItem::factory()->create(['purchase_order_id' => $order->id]);
        PurchaseReceipt::factory()->create(['purchase_order_id' => $order->id]);
        VendorBill::factory()->create(['supplier_id' => $supplier->id, 'purchase_order_id' => $order->id]);
        VendorPayment::factory()->create(['supplier_id' => $supplier->id]);
        $before = [];
        foreach (array_keys($tables) as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }
        foreach (['2026_10_04_000005_prepare_purchasing_composite_reference_keys.php', '2026_10_04_000009_prepare_vendor_composite_reference_keys.php'] as $file) {
            $migration = require app_path('Modules/Purchasing/Database/Migrations/'.$file);
            $migration->up();
            $migration->up();
        }
        foreach ($tables as $tableName => $column) {
            $indexes = collect(Schema::getIndexes($tableName))->filter(fn (array $index): bool => $index['unique'] && $index['columns'] === ['id', $column]);
            $this->assertCount(1, $indexes);
            $this->assertEquals($before[$tableName], DB::table($tableName)->orderBy('id')->get()->toArray());
            $this->assertDatabaseCount($tableName, 1);
        }

        $other = Supplier::factory()->create();
        $this->expectException(QueryException::class);
        VendorBill::factory()->create(['purchase_order_id' => $order->id, 'supplier_id' => $other->id]);
    }
}
