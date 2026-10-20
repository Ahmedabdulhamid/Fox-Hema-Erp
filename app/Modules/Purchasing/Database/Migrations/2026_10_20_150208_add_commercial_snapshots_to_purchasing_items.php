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
        foreach (['purchase_order_items', 'vendor_bill_items'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->json('commercial_snapshot')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['purchase_order_items', 'vendor_bill_items'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('commercial_snapshot');
            });
        }
    }
};
