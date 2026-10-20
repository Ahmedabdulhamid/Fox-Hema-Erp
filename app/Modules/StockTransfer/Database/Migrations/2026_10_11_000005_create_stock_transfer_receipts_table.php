<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_number', 60)->unique();
            $table->foreignId('stock_transfer_id');
            $table->foreignId('stock_transfer_shipment_id');
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->dateTime('received_at')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->foreign(['stock_transfer_shipment_id', 'stock_transfer_id'], 'fk_transfer_receipt_shipment')
                ->references(['id', 'stock_transfer_id'])->on('stock_transfer_shipments')->restrictOnDelete();
            $table->index(['stock_transfer_id', 'status']);
            $table->unique(['id', 'stock_transfer_shipment_id'], 'uq_transfer_receipt_shipment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_receipts');
    }
};
