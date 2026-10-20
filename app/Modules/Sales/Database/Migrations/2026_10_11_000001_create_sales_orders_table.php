<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number', 60)->unique();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('channel', 30)->default('manual'); // manual, pos, shopify
            $table->string('external_order_id', 150)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->enum('status', ['draft', 'confirmed', 'partially_fulfilled', 'fulfilled', 'closed', 'cancelled'])->default('draft');
            $table->enum('fulfillment_status', ['unfulfilled', 'partial', 'fulfilled'])->default('unfulfilled');
            $table->dateTime('ordered_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('total_amount', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->index(['location_id', 'status']);
            $table->index(['channel', 'external_order_id']);
            $table->unique(['id', 'location_id'], 'uq_sales_order_location');
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('sales_orders');
    }
};
