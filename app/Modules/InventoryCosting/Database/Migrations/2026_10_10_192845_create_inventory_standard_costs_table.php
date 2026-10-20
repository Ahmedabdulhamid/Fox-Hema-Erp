<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_standard_costs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('variant_id')
                ->unique()
                ->constrained('product_variants')
                ->restrictOnDelete();

            $table->decimal('standard_cost', 18, 6);

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_standard_costs');
    }
};
