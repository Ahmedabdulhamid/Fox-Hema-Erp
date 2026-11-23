<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_device_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('pos_device_id')->constrained('pos_devices')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->foreignId('treasury_account_id')->nullable()->constrained('treasury_accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['pos_device_id', 'payment_method_id'], 'uq_pos_device_method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_device_payment_methods');
    }
};
