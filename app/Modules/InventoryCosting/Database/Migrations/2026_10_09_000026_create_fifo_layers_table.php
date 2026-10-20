<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fifo_layers', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();

            $table->foreignId('stock_movement_id')
                ->unique()
                ->constrained('stock_movements')
                ->restrictOnDelete();

            $table->decimal('quantity', 18, 4);

            $table->decimal('unit_cost', 18, 6);

            $table->decimal('remaining_qty', 18, 4);

            $table->timestamp('received_at');

            $table->index(
                ['variant_id', 'received_at', 'id'],
                'idx_fifo_variant_date'
            );

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fifo_layers');
    }
};
