<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Payments follows Purchasing in module order, so this cross-module constraint is installed last. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_payments', function (Blueprint $table): void {
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('vendor_payments')->whereNotNull('idempotency_key')->exists()) {
            throw new LogicException('Cannot remove governed payment method references from financial history.');
        }
        Schema::table('vendor_payments', function (Blueprint $table): void {
            $table->dropForeign(['payment_method_id']);
        });
    }
};
