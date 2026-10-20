<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_receipt_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('purchase_order_id');

            $table->foreignId('purchase_receipt_id');

            $table->foreignId('purchase_order_item_id');

            $table->decimal('quantity', 18, 4);

            $table->decimal('unit_cost', 18, 6);

            $table->foreignId('stock_movement_id')
                ->nullable()
                ->unique()
                ->constrained('stock_movements')
                ->restrictOnDelete();

            $table->timestamps();

            $table->foreign(
                ['purchase_receipt_id', 'purchase_order_id'],
                'fk_receipt_item_receipt_order'
            )
                ->references(['id', 'purchase_order_id'])
                ->on('purchase_receipts')
                ->restrictOnDelete();

            $table->foreign(
                ['purchase_order_item_id', 'purchase_order_id'],
                'fk_receipt_item_po_item_order'
            )
                ->references(['id', 'purchase_order_id'])
                ->on('purchase_order_items')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_items');
    }
};
