<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_inventory_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->date('report_date');
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('closing_quantity', 28, 4)->nullable();
            $table->decimal('closing_value', 28, 4)->nullable();
            $table->dateTime('projected_at', 6);
            $table->unique(['location_id', 'report_date', 'variant_id'], 'reporting_inventory_day_unique');
            $table->index(['report_date', 'location_id']);
        });
        Schema::create('reporting_inventory_movement_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->date('report_date');
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('type', 80);
            $table->unsignedBigInteger('movement_count');
            $table->unsignedBigInteger('reversal_count');
            $table->decimal('received_quantity', 28, 4);
            $table->decimal('issued_quantity', 28, 4);
            $table->dateTime('projected_at', 6);
            $table->unique(['location_id', 'report_date', 'variant_id', 'type'], 'reporting_inventory_movement_unique');
            $table->index(['report_date', 'location_id']);
        });
        Schema::create('reporting_inventory_partitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->date('report_date');
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedBigInteger('projected_revision')->default(0);
            $table->dateTime('requested_at', 6);
            $table->dateTime('projected_at', 6)->nullable();
            $table->dateTime('dispatched_at', 6)->nullable();
            $table->unique(['location_id', 'report_date'], 'reporting_inventory_partition_unique');
            $table->index(['requested_at', 'id'], 'reporting_inventory_pending_order');
        });
        Schema::create('reporting_inventory_checkpoints', function (Blueprint $table): void {
            $table->string('source', 40)->primary();
            $table->unsignedBigInteger('last_id')->default(0);
            $table->dateTime('scanned_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_inventory_checkpoints');
        Schema::dropIfExists('reporting_inventory_partitions');
        Schema::dropIfExists('reporting_inventory_movement_daily');
        Schema::dropIfExists('reporting_inventory_daily');
    }
};
