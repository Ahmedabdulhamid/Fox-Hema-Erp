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
        Schema::table('inventory_cost_entries', function (Blueprint $table): void {
            $table->decimal('purchase_unit_cost', 18, 6)->nullable();
            $table->decimal('purchase_value', 20, 4)->nullable();
            $table->decimal('purchase_price_variance', 20, 4)->nullable();
            $table->foreignId('standard_cost_revision_id')->nullable()
                ->constrained('inventory_standard_cost_revisions')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Receipt valuation history must not be dropped by automatic rollback.');
    }
};
