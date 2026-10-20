<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->char('confirmation_payload_hash', 64)->nullable();
            $table->char('cancellation_payload_hash', 64)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
        });
        Schema::create('sales_stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('sales_order_id');
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->decimal('quantity_reserved', 18, 4);
            $table->decimal('quantity_consumed', 18, 4)->default(0);
            $table->decimal('quantity_released', 18, 4)->default(0);
            $table->foreign(['sales_order_id', 'location_id'], 'fk_sales_reservation_order_branch')
                ->references(['id', 'location_id'])->on('sales_orders')->restrictOnDelete();
            $table->unique(['sales_order_id', 'variant_id', 'location_id'], 'uq_sales_reservation_owner');
            $table->index(['variant_id', 'location_id'], 'idx_sales_reservation_balance');
            $table->timestamps();
        });
        DB::statement('ALTER TABLE sales_stock_reservations ADD CONSTRAINT chk_sales_reservation_quantities
            CHECK (quantity_reserved > 0 AND quantity_consumed >= 0 AND quantity_released >= 0
                AND quantity_consumed + quantity_released <= quantity_reserved)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Reservation and cancellation history requires a reviewed, data-preserving rollback.');
    }
};
