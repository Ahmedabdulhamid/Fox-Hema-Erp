<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_device_payment_methods')) {
            return;
        }

        Schema::table('pos_device_payment_methods', function (Blueprint $table): void {
            $table->dropForeign(['treasury_account_id']);
            $table->unsignedBigInteger('treasury_account_id')->nullable()->change();
            $table->foreign('treasury_account_id')->references('id')->on('treasury_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new LogicException('POS payment account mappings are forward-only to preserve account-less non-cash assignments.');
    }
};
