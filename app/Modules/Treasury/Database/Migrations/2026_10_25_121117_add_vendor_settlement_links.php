<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vendor_payments', function (Blueprint $table): void {
            $table->foreignId('treasury_account_id')->nullable()->constrained('treasury_accounts')->restrictOnDelete();
            $table->string('recovery_reference', 191)->nullable();
        });
        Schema::table('treasury_operations', function (Blueprint $table): void {
            $table->foreignId('vendor_payment_id')->nullable()->unique()->constrained('vendor_payments')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Vendor settlement evidence requires a separately reviewed non-destructive rollback.');
    }
};
