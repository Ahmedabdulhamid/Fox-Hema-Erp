<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_bill_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('vendor_bill_id')
                ->constrained('vendor_bills')
                ->restrictOnDelete();

            $table->foreignId('variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->restrictOnDelete();

            $table->string('description')->nullable();

            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 6);

            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_bill_items');
    }
};
