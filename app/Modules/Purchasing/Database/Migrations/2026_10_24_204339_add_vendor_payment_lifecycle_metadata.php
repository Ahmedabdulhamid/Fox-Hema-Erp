<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_payments', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->index();
            $table->string('currency', 3)->nullable();
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->char('payload_fingerprint', 64)->nullable();
            $table->uuid('reversal_key')->nullable()->unique();
            $table->char('reversal_fingerprint', 64)->nullable();
            $table->text('reversal_reason')->nullable();
            $table->index(['location_id', 'supplier_id', 'status'], 'idx_vendor_payment_ownership');
        });
        Schema::table('vendor_payment_allocations', function (Blueprint $table): void {
            $table->uuid('operation_key')->nullable()->unique();
            $table->char('payload_fingerprint', 64)->nullable();
            $table->foreignId('allocated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('allocated_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->uuid('release_key')->nullable()->unique();
            $table->char('release_fingerprint', 64)->nullable();
            $table->index(['vendor_payment_id', 'vendor_bill_id'], 'idx_vendor_payment_tranches');
        });
        Schema::table('vendor_payment_allocations', function (Blueprint $table): void {
            $table->dropUnique('uq_vendor_payment_bill');
        });
    }

    public function down(): void
    {
        if (DB::table('vendor_payments')->whereNotNull('idempotency_key')->exists()
            || DB::table('vendor_payment_allocations')->whereNotNull('operation_key')->exists()) {
            throw new LogicException('Cannot discard financial ownership, reversal or allocation audit evidence.');
        }
        if (DB::table('vendor_payment_allocations')->select('vendor_payment_id', 'vendor_bill_id')->groupBy('vendor_payment_id', 'vendor_bill_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new LogicException('Cannot remove allocation lifecycle metadata while historical tranches exist.');
        }
        Schema::table('vendor_payment_allocations', function (Blueprint $table): void {
            $table->unique(['vendor_payment_id', 'vendor_bill_id'], 'uq_vendor_payment_bill');
            $table->dropForeign(['allocated_by']);
            $table->dropForeign(['released_by']);
            $table->dropIndex('idx_vendor_payment_tranches');
            $table->dropColumn(['operation_key', 'payload_fingerprint', 'allocated_by', 'allocated_at', 'released_by', 'released_at', 'release_reason', 'release_key', 'release_fingerprint']);
        });
        Schema::table('vendor_payments', function (Blueprint $table): void {
            $table->dropForeign(['location_id']);
            $table->dropIndex('idx_vendor_payment_ownership');
            $table->dropColumn(['location_id', 'payment_method_id', 'currency', 'idempotency_key', 'payload_fingerprint', 'reversal_key', 'reversal_fingerprint', 'reversal_reason']);
        });
    }
};
