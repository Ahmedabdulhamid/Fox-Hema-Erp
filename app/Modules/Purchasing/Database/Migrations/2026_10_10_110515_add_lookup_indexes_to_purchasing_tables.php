<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->index(['is_active', 'name'], 'idx_suppliers_active_name');
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->index(['location_id', 'status', 'order_date'], 'idx_po_location_status_date');
            $table->index(['supplier_id', 'order_date'], 'idx_po_supplier_date');
            $table->index(['status', 'expected_date'], 'idx_po_status_expected_date');
        });

        Schema::table('purchase_receipts', function (Blueprint $table): void {
            $table->index(['location_id', 'status', 'received_at'], 'idx_receipts_location_status_date');
            $table->index(['purchase_order_id', 'status'], 'idx_receipts_order_status');
        });

        Schema::table('vendor_bills', function (Blueprint $table): void {
            $table->index(['status', 'due_date'], 'idx_bills_status_due_date');
            $table->index(['supplier_id', 'status', 'bill_date'], 'idx_bills_supplier_status_date');
        });

        Schema::table('vendor_payments', function (Blueprint $table): void {
            $table->index(['supplier_id', 'status', 'payment_date'], 'idx_payments_supplier_status_date');
            $table->index(['status', 'payment_date'], 'idx_payments_status_date');
        });

        Schema::table('vendor_payment_allocations', function (Blueprint $table): void {
            $table->index(['vendor_bill_id', 'vendor_payment_id'], 'idx_allocations_bill_payment');
        });

        Schema::table('vendor_bill_receipt_matches', function (Blueprint $table): void {
            $table->index(['purchase_receipt_item_id', 'vendor_bill_item_id'], 'idx_matches_receipt_bill_item');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropIndex('idx_suppliers_active_name');
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropIndex('idx_po_location_status_date');
            $table->dropIndex('idx_po_supplier_date');
            $table->dropIndex('idx_po_status_expected_date');
        });

        Schema::table('purchase_receipts', function (Blueprint $table): void {
            $table->dropIndex('idx_receipts_location_status_date');
            $table->dropIndex('idx_receipts_order_status');
        });

        Schema::table('vendor_bills', function (Blueprint $table): void {
            $table->dropIndex('idx_bills_status_due_date');
            $table->dropIndex('idx_bills_supplier_status_date');
        });

        Schema::table('vendor_payments', function (Blueprint $table): void {
            $table->dropIndex('idx_payments_supplier_status_date');
            $table->dropIndex('idx_payments_status_date');
        });

        Schema::table('vendor_payment_allocations', function (Blueprint $table): void {
            $table->dropIndex('idx_allocations_bill_payment');
        });

        Schema::table('vendor_bill_receipt_matches', function (Blueprint $table): void {
            $table->dropIndex('idx_matches_receipt_bill_item');
        });
    }
};
