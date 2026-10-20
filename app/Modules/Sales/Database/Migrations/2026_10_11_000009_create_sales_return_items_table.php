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
        Schema::create('sales_return_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('sales_return_id')
                ->constrained('sales_returns')
                ->restrictOnDelete();

            $table->foreignId('sales_delivery_item_id')
                ->constrained('sales_delivery_items')
                ->restrictOnDelete();

            $table->foreignId('sales_invoice_item_id')
                ->nullable()
                ->constrained('sales_invoice_items')
                ->restrictOnDelete();

            $table->foreignId('variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();

            $table->decimal('quantity', 18, 4);

            $table->decimal('unit_price', 18, 6);

            $table->decimal('refund_amount', 20, 4)->default(0);

            $table->enum('disposition', [
                'restock',
                'damaged',
                'scrap',
            ])->default('restock');

            $table->text('notes')->nullable();

            $table->index(
                ['sales_delivery_item_id', 'disposition'],
                'idx_return_items_delivery_disposition'
            );

            $table->index('sales_invoice_item_id');

            $table->index('variant_id');

            $table->timestamps();
        });

        DB::statement('
    ALTER TABLE sales_return_items
    ADD CONSTRAINT chk_sales_return_items_values
    CHECK (
        quantity > 0
        AND unit_price >= 0
        AND refund_amount >= 0
    )
');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_items');
    }
};
