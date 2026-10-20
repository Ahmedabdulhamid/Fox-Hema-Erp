<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->timestamps();
            $table->unique(['payment_id', 'sales_invoice_id'], 'uq_payment_invoice_allocation');
            $table->index(['sales_invoice_id']);
        });

        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT chk_payment_allocation_amount CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
