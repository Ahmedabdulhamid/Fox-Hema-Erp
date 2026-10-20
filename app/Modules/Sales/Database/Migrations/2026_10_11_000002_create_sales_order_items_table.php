<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('description')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 6);
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->index(['sales_order_id', 'variant_id']);
            $table->unique(['id', 'sales_order_id'], 'uq_sales_order_item_parent');
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_items');
    }
};
