<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_shipments', function (Blueprint $table): void {
            $table->id();
            $table->string('shipment_number', 60)->unique();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->restrictOnDelete();
            $table->enum('status', ['draft', 'dispatched', 'cancelled'])->default('draft');
            $table->dateTime('dispatched_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->dateTime('cancelled_at')->nullable();

            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->index(['stock_transfer_id', 'status']);
            $table->unique(['id', 'stock_transfer_id'], 'uq_transfer_shipment_parent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_shipments');
    }
};
