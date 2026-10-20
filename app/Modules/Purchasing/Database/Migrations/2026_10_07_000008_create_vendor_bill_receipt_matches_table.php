<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_bill_receipt_matches', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('vendor_bill_item_id')
                ->constrained('vendor_bill_items')
                ->restrictOnDelete();

            $table->foreignId('purchase_receipt_item_id')
                ->constrained('purchase_receipt_items')
                ->restrictOnDelete();

            $table->decimal('matched_quantity', 18, 4);

            $table->unique(
                ['vendor_bill_item_id', 'purchase_receipt_item_id'],
                'uq_bill_receipt_match'
            );

            $table->index('purchase_receipt_item_id');

            $table->timestamps();
        });

        DB::statement('
    ALTER TABLE vendor_bill_receipt_matches
    ADD CONSTRAINT chk_bill_receipt_match_positive
    CHECK (matched_quantity > 0)
');
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_bill_receipt_matches');
    }
};
