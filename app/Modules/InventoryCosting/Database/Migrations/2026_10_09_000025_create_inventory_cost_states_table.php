<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_cost_states', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('variant_id')
                ->unique()
                ->constrained('product_variants')
                ->restrictOnDelete();

            $table->decimal('avg_cost', 18, 6)
                ->default(0);

            $table->decimal('on_hand_qty', 18, 4)
                ->default(0);

            $table->decimal('total_value', 20, 4)
                ->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cost_states');
    }
};
