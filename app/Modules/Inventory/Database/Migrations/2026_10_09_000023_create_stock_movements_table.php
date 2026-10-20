<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('type'); // purchase, sale, return, transfer_in, transfer_out, adjustment
            $table->decimal('quantity', 18, 4); // signed: inbound positive, outbound negative
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->nullableMorphs('reference');
            $table->string('idempotency_key')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->index(['variant_id', 'location_id', 'created_at']);
            $table->index(['location_id', 'created_at'], 'idx_movements_location_date');
            $table->index(['type', 'created_at'], 'idx_movements_type_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
