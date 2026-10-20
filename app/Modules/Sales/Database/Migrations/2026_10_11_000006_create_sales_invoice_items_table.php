<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoice_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('sales_invoice_id')
                ->constrained('sales_invoices')
                ->restrictOnDelete();

            $table->foreignId('sales_order_item_id')
                ->nullable()
                ->constrained('sales_order_items')
                ->restrictOnDelete();

            $table->foreignId('variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->restrictOnDelete();

            $table->string('description')->nullable();

            $table->enum('line_type', [
                'product',
                'service',
                'charge',
            ])->default('product');

            $table->decimal('quantity', 18, 4);

            $table->decimal('unit_price', 18, 6);

            $table->decimal('subtotal', 20, 4)->default(0);

            $table->decimal('discount_amount', 20, 4)->default(0);

            $table->decimal('tax_amount', 20, 4)->default(0);

            $table->decimal('line_total', 20, 4)->default(0);

            $table->index(
                ['sales_invoice_id', 'variant_id'],
                'idx_invoice_items_variant'
            );

            $table->index(
                'sales_order_item_id',
                'idx_invoice_items_order_item'
            );

            $table->unique(
                ['id', 'sales_invoice_id'],
                'uq_invoice_item_parent'
            );

            $table->timestamps();
        });

        DB::statement('
    ALTER TABLE sales_invoice_items
    ADD CONSTRAINT chk_sales_invoice_items_values
    CHECK (
        quantity > 0
        AND unit_price >= 0
        AND subtotal >= 0
        AND discount_amount >= 0
        AND tax_amount >= 0
        AND line_total >= 0
        AND discount_amount <= subtotal
    )
');

        DB::statement("
    ALTER TABLE sales_invoice_items
    ADD CONSTRAINT chk_sales_invoice_items_product
    CHECK (
        line_type <> 'product'
        OR variant_id IS NOT NULL
    )
");
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_items');
    }
};
