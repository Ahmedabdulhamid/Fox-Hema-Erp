<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_receipt_id');
            $table->foreignId('stock_transfer_shipment_id');
            $table->foreignId('stock_transfer_shipment_item_id');
            $table->decimal('received_quantity', 18, 4);
            $table->decimal('damaged_quantity', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->foreign(['stock_transfer_receipt_id', 'stock_transfer_shipment_id'], 'fk_receipt_item_receipt')
                ->references(['id', 'stock_transfer_shipment_id'])->on('stock_transfer_receipts')->restrictOnDelete();
            $table->foreign(['stock_transfer_shipment_item_id', 'stock_transfer_shipment_id'], 'fk_receipt_item_shipment_item')
                ->references(['id', 'stock_transfer_shipment_id'])->on('stock_transfer_shipment_items')->restrictOnDelete();
            $table->unique(['stock_transfer_receipt_id', 'stock_transfer_shipment_item_id'], 'uq_receipt_shipment_item');
        });

        DB::statement('ALTER TABLE stock_transfer_receipt_items ADD CONSTRAINT chk_transfer_receipt_qty CHECK (received_quantity >= 0 AND damaged_quantity >= 0 AND (received_quantity + damaged_quantity) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_receipt_items');
    }
};
