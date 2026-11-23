<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['reporting_sales_daily', 'reporting_sales_variant_daily'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->date('report_date');
                $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
                $table->string('currency', 3);
                $key = ['location_id', 'report_date', 'currency'];
                if ($name === 'reporting_sales_variant_daily') {
                    $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
                    $table->decimal('invoice_quantity', 28, 4)->default(0);
                    $table->decimal('credit_quantity', 28, 4)->default(0);
                    $key[] = 'variant_id';
                } else {
                    $table->unsignedBigInteger('invoice_count')->default(0);
                    $table->unsignedBigInteger('credit_count')->default(0);
                }
                foreach (['invoice', 'credit'] as $source) {
                    foreach (['subtotal', 'discount', 'tax', 'total'] as $amount) {
                        $table->decimal($source.'_'.$amount, 28, 4)->default(0);
                    }
                }
                $table->dateTime('projected_at', 6);
                $table->unique($key, $name === 'reporting_sales_daily' ? 'reporting_sales_day_unique' : 'reporting_sales_variant_day_unique');
                $table->index(['report_date', 'location_id']);
            });
        }

        Schema::create('reporting_sales_partitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->date('report_date');
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedBigInteger('projected_revision')->default(0);
            $table->dateTime('requested_at', 6);
            $table->dateTime('projected_at', 6)->nullable();
            $table->dateTime('dispatched_at', 6)->nullable();
            $table->unique(['location_id', 'report_date'], 'reporting_sales_partition_unique');
            $table->index(['requested_at', 'id'], 'reporting_sales_pending_order');
        });

        Schema::create('reporting_sales_checkpoints', function (Blueprint $table): void {
            $table->string('source', 40)->primary();
            $table->unsignedBigInteger('last_id')->default(0);
            $table->dateTime('scanned_at', 6)->nullable();
        });

        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->index(['location_id', 'invoice_date', 'status'], 'reporting_invoice_day');
        });
        Schema::table('sales_credit_notes', function (Blueprint $table): void {
            $table->index(['location_id', 'credit_date', 'status'], 'reporting_credit_day');
        });
    }

    public function down(): void
    {
        Schema::table('sales_credit_notes', function (Blueprint $table): void {
            $table->dropIndex('reporting_credit_day');
        });
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->dropIndex('reporting_invoice_day');
        });
        Schema::dropIfExists('reporting_sales_checkpoints');
        Schema::dropIfExists('reporting_sales_partitions');
        Schema::dropIfExists('reporting_sales_variant_daily');
        Schema::dropIfExists('reporting_sales_daily');
    }
};
