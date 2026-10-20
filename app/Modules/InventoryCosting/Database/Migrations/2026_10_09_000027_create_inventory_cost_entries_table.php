<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_cost_entries', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('stock_movement_id')
                ->unique()
                ->constrained('stock_movements')
                ->restrictOnDelete();

            $table->enum('method', [
                'standard',
                'avco',
                'fifo',
            ]);

            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 6);
            $table->decimal('total_cost', 20, 4);

            $table->enum('entry_type', [
                'receipt',
                'issue',
                'adjustment',
                'reversal',
            ]);

            $table->index(['entry_type', 'created_at']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cost_entries');
    }
};
