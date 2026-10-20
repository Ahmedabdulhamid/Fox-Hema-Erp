<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_bills', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->restrictOnDelete();

            $table->foreignId('purchase_order_id')->nullable();

            $table->string('bill_number');

            $table->date('bill_date');
            $table->date('due_date')->nullable();

            $table->enum('status', [
                'draft',
                'posted',
                'cancelled',
            ])->default('draft');

            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('total_amount', 20, 4)->default(0);

            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('posted_at')->nullable();

            $table->unique(
                ['supplier_id', 'bill_number'],
                'uq_vendor_bills_supplier_number'
            );

            $table->index(['supplier_id', 'due_date']);
            $table->index(['status', 'due_date']);

            $table->foreign(
                ['purchase_order_id', 'supplier_id'],
                'fk_vendor_bills_order_supplier'
            )
                ->references(['id', 'supplier_id'])
                ->on('purchase_orders')
                ->restrictOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_bills');
    }
};
