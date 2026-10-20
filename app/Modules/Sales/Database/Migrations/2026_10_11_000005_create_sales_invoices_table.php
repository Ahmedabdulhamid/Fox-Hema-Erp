<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_number', 60)->unique();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('total_amount', 20, 4)->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->index(['location_id', 'status']);
            $table->index(['status', 'due_date']);
            $table->unique(['id', 'sales_order_id'], 'uq_invoice_order');
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoices');
    }
};
