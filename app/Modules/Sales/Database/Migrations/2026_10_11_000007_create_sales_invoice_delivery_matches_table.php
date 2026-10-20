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
        Schema::create('sales_invoice_delivery_matches', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('sales_invoice_item_id')
                ->constrained('sales_invoice_items')
                ->restrictOnDelete();

            $table->foreignId('sales_delivery_item_id')
                ->constrained('sales_delivery_items')
                ->restrictOnDelete();

            $table->decimal('matched_quantity', 18, 4);

            $table->unique(
                ['sales_invoice_item_id', 'sales_delivery_item_id'],
                'uq_invoice_delivery_match'
            );

            $table->index(
                'sales_delivery_item_id',
                'idx_invoice_delivery_matches_delivery'
            );

            $table->timestamps();
        });

        DB::statement('
    ALTER TABLE sales_invoice_delivery_matches
    ADD CONSTRAINT chk_invoice_delivery_match_qty_positive
    CHECK (matched_quantity > 0)
');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_delivery_matches');
    }
};
