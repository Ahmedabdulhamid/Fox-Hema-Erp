<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_purchasing_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->date('report_date');
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->char('currency', 3);
            $table->string('event', 24);
            $table->string('status', 24);
            $table->unsignedBigInteger('variant_id')->default(0);
            foreach (['document_count', 'quantity', 'amount', 'remaining', 'missing', 'partial_count', 'complete_count'] as $field) {
                $table->decimal($field, 28, 4)->default(0);
            }
            $table->dateTime('projected_at', 6);
            $table->unique(['location_id', 'report_date', 'supplier_id', 'currency', 'event', 'status', 'variant_id'], 'reporting_purchasing_day_unique');
            $table->index(['report_date', 'location_id', 'supplier_id'], 'reporting_purchasing_date_scope');
            $table->index(['location_id', 'event', 'variant_id', 'report_date'], 'reporting_purchasing_variant');
        });
        Schema::create('reporting_purchasing_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->char('currency', 3);
            foreach (['billed', 'paid', 'outstanding', 'advances', 'net_balance', 'missing'] as $field) {
                $table->decimal($field, 28, 4);
            }
            $table->dateTime('projected_at', 6);
            $table->unique(['location_id', 'supplier_id', 'currency'], 'reporting_purchasing_balance_unique');
        });
        Schema::create('reporting_purchasing_partitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->date('report_date');
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedBigInteger('projected_revision')->default(0);
            $table->dateTime('requested_at', 6);
            $table->dateTime('projected_at', 6)->nullable();
            $table->dateTime('dispatched_at', 6)->nullable();
            $table->unique(['location_id', 'report_date'], 'reporting_purchasing_partition_unique');
            $table->index(['requested_at', 'id'], 'reporting_purchasing_pending_order');
        });
        Schema::create('reporting_purchasing_checkpoints', function (Blueprint $table): void {
            $table->string('source', 40)->primary();
            $table->unsignedBigInteger('last_id')->default(0);
            $table->dateTime('scanned_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        foreach (['checkpoints', 'partitions', 'balances', 'daily'] as $suffix) {
            Schema::dropIfExists('reporting_purchasing_'.$suffix);
        }
    }
};
