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
        Schema::create('definition_history_test', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_id')->nullable()->constrained('taxes')->cascadeOnDelete();
            $table->foreignId('discount_id')->nullable()->constrained('discounts')->cascadeOnDelete();
            $table->decimal('total', 20, 4);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('definition_history_test');
    }
};
