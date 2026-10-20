<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('purchase_order_id')
                ->constrained('purchase_orders')
                ->restrictOnDelete();

            $table->foreignId('variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();

            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 6);

            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4);

            $table->text('notes')->nullable();

            $table->index(
                ['purchase_order_id', 'variant_id'],
                'idx_po_items_order_variant'
            );

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
