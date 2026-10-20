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
        if (DB::table('sales_deliveries as deliveries')->join('sales_orders as orders', 'orders.id', '=', 'deliveries.sales_order_id')
            ->whereColumn('deliveries.location_id', '!=', 'orders.location_id')->exists()) {
            throw new LogicException('Delivery locations conflict with their orders. Review historical ownership before applying this migration; no records were changed.');
        }
        Schema::table('sales_deliveries', function (Blueprint $table): void {
            $table->foreign(['sales_order_id', 'location_id'], 'fk_sales_delivery_order_branch')
                ->references(['id', 'location_id'])->on('sales_orders')->restrictOnDelete();
            $table->char('submission_payload_hash', 64)->nullable();
            $table->char('cancellation_payload_hash', 64)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['location_id', 'status'], 'idx_sales_delivery_branch_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Delivery lifecycle audit requires a reviewed data-preserving rollback.');
    }
};
