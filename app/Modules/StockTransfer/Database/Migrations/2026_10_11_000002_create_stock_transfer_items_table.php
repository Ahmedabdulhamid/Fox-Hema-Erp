<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('requested_quantity', 18, 4);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['stock_transfer_id', 'variant_id'], 'uq_transfer_variant');
            $table->unique(['id', 'stock_transfer_id'], 'uq_transfer_item_parent');
        });

        DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT chk_transfer_item_qty CHECK (requested_quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
    }
};
