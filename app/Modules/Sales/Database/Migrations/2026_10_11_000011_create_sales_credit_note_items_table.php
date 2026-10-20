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
        Schema::create('sales_credit_note_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('sales_credit_note_id')
                ->constrained('sales_credit_notes')
                ->restrictOnDelete();

            $table->foreignId('sales_invoice_item_id')
                ->constrained('sales_invoice_items')
                ->restrictOnDelete();

            $table->foreignId('sales_return_item_id')
                ->nullable()
                ->constrained('sales_return_items')
                ->restrictOnDelete();

            $table->decimal('quantity', 18, 4);

            $table->decimal('unit_price', 18, 6);

            $table->decimal('subtotal', 20, 4)->default(0);

            $table->decimal('discount_amount', 20, 4)->default(0);

            $table->decimal('tax_amount', 20, 4)->default(0);

            $table->decimal('line_total', 20, 4)->default(0);

            $table->index(
                'sales_invoice_item_id',
                'idx_credit_note_items_invoice_item'
            );

            $table->index(
                'sales_return_item_id',
                'idx_credit_note_items_return_item'
            );

            $table->unique(
                ['id', 'sales_credit_note_id'],
                'uq_credit_note_item_parent'
            );

            $table->timestamps();
        });

        DB::statement('
    ALTER TABLE sales_credit_note_items
    ADD CONSTRAINT chk_credit_note_items_values
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
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_credit_note_items');
    }
};
