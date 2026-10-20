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
        if (DB::table('sales_stock_reservations as r')->leftJoin('sales_orders as o', 'o.id', '=', 'r.sales_order_id')
            ->where(fn ($query) => $query->whereNull('o.id')->orWhereColumn('r.location_id', '!=', 'o.location_id'))->exists()
            || DB::table('sales_delivery_items as d')->leftJoin('sales_order_items as i', 'i.id', '=', 'd.sales_order_item_id')
                ->where(fn ($query) => $query->whereNull('i.id')->orWhereColumn('d.sales_order_id', '!=', 'i.sales_order_id'))->exists()) {
            throw new LogicException('Historical Sales ownership conflicts require review before adding posting constraints. No records were repaired.');
        }
        Schema::table('sales_deliveries', function (Blueprint $table): void {
            $table->char('posting_payload_hash', 64)->nullable();
            $table->char('posting_evidence_hash', 64)->nullable();
            $table->char('posting_revision', 64)->nullable();
        });
        Schema::table('sales_stock_reservations', fn (Blueprint $table) => $table->unique(['id', 'sales_order_id', 'variant_id', 'location_id'], 'uq_sales_reservation_evidence_owner'));
        Schema::table('sales_order_items', fn (Blueprint $table) => $table->unique(['id', 'sales_order_id', 'variant_id'], 'uq_sales_order_item_variant_owner'));
        Schema::create('sales_reservation_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('sales_stock_reservation_id');
            $table->unsignedBigInteger('sales_delivery_item_id')->unique();
            $table->unsignedBigInteger('sales_order_item_id');
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('variant_id');
            $table->unsignedBigInteger('location_id');
            $table->decimal('quantity', 18, 4);
            $table->foreignId('consumed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('consumed_at');
            $table->index(['sales_order_id', 'id'], 'idx_sales_consumption_order_history');
            $table->foreign(['sales_stock_reservation_id', 'sales_order_id', 'variant_id', 'location_id'], 'fk_sales_consumption_reservation_owner')
                ->references(['id', 'sales_order_id', 'variant_id', 'location_id'])->on('sales_stock_reservations')->restrictOnDelete();
            $table->foreign(['sales_order_item_id', 'sales_order_id', 'variant_id'], 'fk_sales_consumption_order_item')
                ->references(['id', 'sales_order_id', 'variant_id'])->on('sales_order_items')->restrictOnDelete();
            $table->foreign(['sales_delivery_item_id', 'sales_order_item_id'], 'fk_sales_consumption_delivery_item')
                ->references(['id', 'sales_order_item_id'])->on('sales_delivery_items')->restrictOnDelete();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE sales_reservation_consumptions ADD CONSTRAINT chk_sales_consumption_positive CHECK (quantity > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Sales posting evidence requires a reviewed data-preserving rollback.');
    }
};
