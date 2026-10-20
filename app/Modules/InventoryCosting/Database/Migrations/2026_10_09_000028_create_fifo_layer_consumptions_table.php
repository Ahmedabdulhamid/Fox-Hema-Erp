<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fifo_layer_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cost_entry_id')->constrained('inventory_cost_entries')->restrictOnDelete();
            $table->foreignId('fifo_layer_id')->constrained('fifo_layers')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 6);
            $table->decimal('total_cost', 20, 4);
            $table->index(['fifo_layer_id', 'created_at']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fifo_layer_consumptions');
    }
};
