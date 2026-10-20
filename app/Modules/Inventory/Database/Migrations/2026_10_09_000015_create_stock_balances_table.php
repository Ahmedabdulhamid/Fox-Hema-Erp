<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->decimal('quantity_on_hand', 18, 4)->default(0);
            $table->decimal('quantity_reserved', 18, 4)->default(0);
            $table->decimal('quantity_available', 18, 4)->default(0); // maintained by application
            $table->unique(['variant_id', 'location_id']);
            $table->index(['location_id', 'variant_id'], 'idx_balances_location_variant');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
