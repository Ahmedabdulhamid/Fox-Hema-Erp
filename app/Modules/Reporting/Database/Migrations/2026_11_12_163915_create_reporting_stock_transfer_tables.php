<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_stock_transfer_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('locations')->restrictOnDelete();
            $table->date('report_date');
            $table->string('event', 24);
            $table->string('status', 24);
            $table->unsignedBigInteger('variant_id')->default(0);
            foreach (['document_count', 'quantity', 'remaining', 'missing', 'partial_count', 'complete_count', 'pending_count', 'damaged', 'reversals', 'partial_dispatch_count', 'partial_receipt_count'] as $field) {
                $table->decimal($field, 28, 4)->default(0);
            }
            $table->dateTime('projected_at', 6);
            $table->unique(['location_id', 'report_date', 'to_location_id', 'event', 'status', 'variant_id'], 'reporting_transfer_day_unique');
            $table->index(['location_id', 'to_location_id', 'report_date'], 'reporting_transfer_route');
            $table->index(['event', 'variant_id', 'report_date'], 'reporting_transfer_variant');
        });
        Schema::create('reporting_stock_transfer_transit', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('locations')->restrictOnDelete();
            $table->unsignedBigInteger('shipment_item_id')->unique();
            $table->unsignedBigInteger('transfer_id');
            $table->unsignedBigInteger('shipment_id');
            $table->unsignedBigInteger('variant_id');
            $table->dateTime('dispatched_at')->nullable();
            foreach (['dispatched', 'received', 'remaining', 'damaged', 'missing', 'reversals'] as $field) {
                $table->decimal($field, 28, 4);
            }
            $table->dateTime('projected_at', 6);
            $table->index(['location_id', 'to_location_id', 'dispatched_at'], 'reporting_transfer_transit_route');
            $table->index(['location_id', 'variant_id'], 'reporting_transfer_transit_variant');
        });
        Schema::create('reporting_stock_transfer_partitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->date('report_date');
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedBigInteger('projected_revision')->default(0);
            $table->dateTime('requested_at', 6);
            $table->dateTime('projected_at', 6)->nullable();
            $table->dateTime('dispatched_at', 6)->nullable();
            $table->unique(['location_id', 'report_date'], 'reporting_transfer_partition_unique');
            $table->index(['requested_at', 'id'], 'reporting_transfer_pending');
        });
        Schema::create('reporting_stock_transfer_checkpoints', function (Blueprint $table): void {
            $table->string('source', 40)->primary();
            $table->unsignedBigInteger('last_id')->default(0);
            $table->dateTime('scanned_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        foreach (['checkpoints', 'partitions', 'transit', 'daily'] as $suffix) {
            Schema::dropIfExists('reporting_stock_transfer_'.$suffix);
        }
    }
};
