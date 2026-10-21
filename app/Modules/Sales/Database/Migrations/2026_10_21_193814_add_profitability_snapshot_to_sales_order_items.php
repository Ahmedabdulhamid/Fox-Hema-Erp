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
        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->json('profitability_snapshot')->nullable();
        });
        Schema::table('sales_deliveries', function (Blueprint $table): void {
            $table->index(['location_id', 'status', 'posted_at'], 'idx_profit_delivery_period');
            $table->index(['status', 'posted_at'], 'idx_profit_delivery_global_period');
        });
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->index(['location_id', 'status', 'posted_at'], 'idx_profit_return_period');
            $table->index(['status', 'posted_at'], 'idx_profit_return_global_period');
        });
        Schema::table('sales_credit_notes', function (Blueprint $table): void {
            $table->index(['location_id', 'status', 'posted_at'], 'idx_profit_credit_period');
            $table->index(['status', 'posted_at'], 'idx_profit_credit_global_period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('sales_order_items')->whereNotNull('profitability_snapshot')->exists()) {
            throw new LogicException('Historical profitability estimates require a reviewed, data-preserving rollback.');
        }
        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->dropColumn('profitability_snapshot');
        });
        Schema::table('sales_deliveries', function (Blueprint $table): void {
            $table->dropIndex('idx_profit_delivery_period');
            $table->dropIndex('idx_profit_delivery_global_period');
        });
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->dropIndex('idx_profit_return_period');
            $table->dropIndex('idx_profit_return_global_period');
        });
        Schema::table('sales_credit_notes', function (Blueprint $table): void {
            $table->dropIndex('idx_profit_credit_period');
            $table->dropIndex('idx_profit_credit_global_period');
        });
    }
};
