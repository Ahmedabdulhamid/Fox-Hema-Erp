<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('purchase_receipt_items')->select('purchase_receipt_id', 'purchase_order_item_id')
            ->groupBy('purchase_receipt_id', 'purchase_order_item_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new LogicException('Duplicate receipt lines require explicit reconciliation before this migration.');
        }
        if (DB::table('purchase_receipts')->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_receipts.purchase_order_id')
            ->whereColumn('purchase_receipts.location_id', '!=', 'purchase_orders.location_id')->exists()) {
            throw new LogicException('Receipt destinations disagree with their orders. Audit and reconcile separately.');
        }
        Schema::table('purchase_receipts', function (Blueprint $table): void {
            $table->char('preparation_key', 64)->nullable()->unique();
            $table->foreignId('backorder_of_id')->nullable()->unique()->constrained('purchase_receipts')->restrictOnDelete();
        });
        Schema::table('purchase_receipt_items', function (Blueprint $table): void {
            $table->decimal('expected_quantity', 18, 4)->nullable();
            $table->unique(['purchase_receipt_id', 'purchase_order_item_id'], 'uq_receipt_order_line');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Receipt preparation history requires a separately reviewed rollback.');
    }
};
