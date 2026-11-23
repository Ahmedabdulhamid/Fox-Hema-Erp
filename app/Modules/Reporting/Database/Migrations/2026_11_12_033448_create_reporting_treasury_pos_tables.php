<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_treasury_daily', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('account_id')->constrained('treasury_accounts')->restrictOnDelete();
            $t->date('report_date');
            $t->char('currency', 3);
            $t->string('category', 20);
            $t->string('direction', 3);
            $t->decimal('amount', 28, 4);
            $t->unsignedBigInteger('entry_count');
            $t->unsignedBigInteger('reversal_count');
            $t->dateTime('projected_at', 6);
            $t->unique(['account_id', 'report_date', 'currency', 'category', 'direction'], 'reporting_treasury_day_unique');
            $t->index(['report_date', 'account_id']);
        });
        Schema::create('reporting_pos_daily', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $t->date('report_date');
            $t->foreignId('session_id')->constrained('pos_sessions')->restrictOnDelete();
            $t->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('device_id')->constrained('pos_devices')->restrictOnDelete();
            $t->char('currency', 3);
            foreach (['sale_count', 'sale_total', 'sale_revenue', 'return_count', 'credit_count', 'credit_total', 'refund_count', 'refund_total', 'refund_recovery_total', 'opened_count', 'closed_count', 'opening_cash', 'expected_cash', 'counted_cash', 'variance', 'missing_close', 'missing_sale', 'received', 'processing', 'completed', 'rejected', 'conflict'] as $field) {
                if (in_array($field, ['sale_total', 'sale_revenue', 'credit_total', 'refund_total', 'refund_recovery_total', 'opening_cash', 'expected_cash', 'counted_cash', 'variance'], true)) {
                    $t->decimal($field, 28, 4)->default(0);
                } else {
                    $t->unsignedBigInteger($field)->default(0);
                }
            }
            $t->dateTime('projected_at', 6);
            $t->unique(['location_id', 'report_date', 'session_id', 'cashier_id', 'device_id', 'currency'], 'reporting_pos_day_unique');
            $t->index(['report_date', 'location_id']);
        });
        foreach (['treasury' => 'account_id', 'pos' => 'location_id'] as $domain => $scope) {
            Schema::create('reporting_'.$domain.'_partitions', function (Blueprint $t) use ($domain, $scope): void {
                $t->id();
                $t->foreignId($scope)->constrained($domain === 'treasury' ? 'treasury_accounts' : 'locations')->restrictOnDelete();
                $t->date('report_date');
                $t->unsignedBigInteger('revision')->default(1);
                $t->unsignedBigInteger('projected_revision')->default(0);
                $t->dateTime('requested_at', 6);
                $t->dateTime('projected_at', 6)->nullable();
                $t->dateTime('dispatched_at', 6)->nullable();
                $t->unique([$scope, 'report_date'], 'reporting_'.$domain.'_partition_unique');
                $t->index(['requested_at', 'id'], 'reporting_'.$domain.'_pending_order');
            });
            Schema::create('reporting_'.$domain.'_checkpoints', function (Blueprint $t): void {
                $t->string('source', 40)->primary();
                $t->unsignedBigInteger('last_id')->default(0);
                $t->dateTime('scanned_at', 6)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['treasury', 'pos'] as $domain) {
            Schema::dropIfExists('reporting_'.$domain.'_checkpoints');
            Schema::dropIfExists('reporting_'.$domain.'_partitions');
            Schema::dropIfExists('reporting_'.$domain.'_daily');
        }
    }
};
