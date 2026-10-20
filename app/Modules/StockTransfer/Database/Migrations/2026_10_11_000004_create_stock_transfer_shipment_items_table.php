<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_shipment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_id');
            $table->foreignId('stock_transfer_shipment_id');
            $table->foreignId('stock_transfer_item_id');
            $table->decimal('shipped_quantity', 18, 4);
            $table->timestamps();
            $table->foreign(['stock_transfer_shipment_id', 'stock_transfer_id'], 'fk_shipment_item_shipment')
                ->references(['id', 'stock_transfer_id'])->on('stock_transfer_shipments')->restrictOnDelete();
            $table->foreign(['stock_transfer_item_id', 'stock_transfer_id'], 'fk_shipment_item_transfer_item')
                ->references(['id', 'stock_transfer_id'])->on('stock_transfer_items')->restrictOnDelete();
            $table->unique(['stock_transfer_shipment_id', 'stock_transfer_item_id'], 'uq_shipment_transfer_item');
            $table->unique(['id', 'stock_transfer_shipment_id'], 'uq_shipment_item_parent');
        });

        DB::statement('ALTER TABLE stock_transfer_shipment_items ADD CONSTRAINT chk_transfer_shipped_qty CHECK (shipped_quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_shipment_items');
    }
};
