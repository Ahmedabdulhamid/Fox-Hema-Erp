<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('default_sku')->nullable()->unique();
            $table->string('status')->default('active');
            $table->boolean('is_active')->default(true);
            $table->index(['name'], 'idx_products_name');
            $table->index(['category_id', 'is_active'], 'idx_products_category_active');
            $table->index(['brand_id', 'is_active'], 'idx_products_brand_active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
