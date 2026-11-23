<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_profitability_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('currency', 3);
            foreach (['revenue', 'known_cost', 'return_quantity', 'return_revenue', 'return_loss', 'return_variance', 'credit_adjustment'] as $field) {
                $table->decimal($field, 28, 4)->default(0);
            }
            $table->unsignedBigInteger('missing')->default(0);
            $table->dateTime('projected_at', 6);
            $table->unique(['location_id', 'report_date', 'variant_id', 'currency'], 'reporting_profitability_day_unique');
            $table->index(['report_date', 'location_id']);
        });
        Schema::create('reporting_profitability_partitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->date('report_date');
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedBigInteger('projected_revision')->default(0);
            $table->dateTime('requested_at', 6);
            $table->dateTime('projected_at', 6)->nullable();
            $table->dateTime('dispatched_at', 6)->nullable();
            $table->unique(['location_id', 'report_date'], 'reporting_profitability_partition_unique');
            $table->index(['requested_at', 'id'], 'reporting_profitability_pending_order');
        });
        Schema::create('reporting_profitability_checkpoints', function (Blueprint $table): void {
            $table->string('source', 40)->primary();
            $table->unsignedBigInteger('last_id')->default(0);
            $table->dateTime('scanned_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_profitability_checkpoints');
        Schema::dropIfExists('reporting_profitability_partitions');
        Schema::dropIfExists('reporting_profitability_daily');
    }
};
