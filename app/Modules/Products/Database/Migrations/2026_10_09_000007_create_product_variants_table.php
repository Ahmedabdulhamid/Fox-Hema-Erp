<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('size_id')->nullable()->constrained('sizes')->nullOnDelete();
            $table->foreignId('color_id')->nullable()->constrained('colors')->nullOnDelete();
            $table->string('sku')->unique();
            $table->string('barcode')->nullable()->unique();
            $table->decimal('sale_price', 18, 4)->default(0);
            $table->decimal('cost_price', 18, 4)->nullable(); // Reference price only, not accounting valuation
            $table->boolean('is_active')->default(true);
            $table->unique(['product_id', 'size_id', 'color_id'], 'variant_product_size_color_unique');
            $table->index(['product_id', 'is_active'], 'idx_variants_product_active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
