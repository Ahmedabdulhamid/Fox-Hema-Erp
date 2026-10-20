<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_number', 60)->unique();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->index(['sales_order_id', 'status']);
            $table->unique(['id', 'sales_order_id'], 'uq_sales_delivery_order');
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('sales_deliveries');
    }
};
