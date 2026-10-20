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
        Schema::create('sales_delivery_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('sales_order_id');

            $table->foreignId('sales_delivery_id');

            $table->foreignId('sales_order_item_id');

            $table->decimal('quantity', 18, 4);

            $table->foreignId('stock_movement_id')
                ->nullable()
                ->unique()
                ->constrained('stock_movements')
                ->restrictOnDelete();

            $table->foreign(
                ['sales_delivery_id', 'sales_order_id'],
                'fk_delivery_item_delivery_order'
            )
                ->references(['id', 'sales_order_id'])
                ->on('sales_deliveries')
                ->restrictOnDelete();

            $table->foreign(
                ['sales_order_item_id', 'sales_order_id'],
                'fk_delivery_item_order_item'
            )
                ->references(['id', 'sales_order_id'])
                ->on('sales_order_items')
                ->restrictOnDelete();

            $table->index('sales_order_item_id');

            $table->unique(
                ['id', 'sales_order_item_id'],
                'uq_delivery_item_order_item'
            );

            $table->timestamps();
        });

        DB::statement('
    ALTER TABLE sales_delivery_items
    ADD CONSTRAINT chk_sales_delivery_item_qty_positive
    CHECK (quantity > 0)
');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_delivery_items');
    }
};
