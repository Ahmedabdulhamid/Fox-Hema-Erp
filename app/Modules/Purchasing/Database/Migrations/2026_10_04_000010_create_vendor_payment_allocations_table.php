<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_payment_allocations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('supplier_id');

            $table->foreignId('vendor_payment_id');

            $table->foreignId('vendor_bill_id');

            $table->decimal('amount', 20, 4);

            $table->unique(
                ['vendor_payment_id', 'vendor_bill_id'],
                'uq_vendor_payment_bill'
            );

            $table->foreign(
                ['vendor_payment_id', 'supplier_id'],
                'fk_allocation_payment_supplier'
            )
                ->references(['id', 'supplier_id'])
                ->on('vendor_payments')
                ->restrictOnDelete();

            $table->foreign(
                ['vendor_bill_id', 'supplier_id'],
                'fk_allocation_bill_supplier'
            )
                ->references(['id', 'supplier_id'])
                ->on('vendor_bills')
                ->restrictOnDelete();

            $table->timestamps();
        });

        DB::statement('
    ALTER TABLE vendor_payment_allocations
    ADD CONSTRAINT chk_vendor_payment_allocation_positive
    CHECK (amount > 0)
');
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payment_allocations');
    }
};
