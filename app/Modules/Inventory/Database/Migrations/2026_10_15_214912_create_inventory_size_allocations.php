<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inventory_size_allocations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference')->unique();
            $table->string('submission_key', 64)->unique();
            $table->string('submission_hash', 64);
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('source_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'product_id', 'status'], 'idx_size_allocations_scope');
        });
        Schema::create('inventory_size_allocation_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('allocation_id')->constrained('inventory_size_allocations')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->timestamps();
            $table->unique(['allocation_id', 'variant_id'], 'uq_size_allocation_target');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Size allocation history requires a reviewed, data-preserving rollback.');
    }
};
