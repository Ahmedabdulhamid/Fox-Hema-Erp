<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_receipts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('purchase_order_id')
                ->constrained('purchase_orders')
                ->restrictOnDelete();

            $table->foreignId('location_id')
                ->constrained('locations')
                ->restrictOnDelete();

            $table->string('receipt_number')->unique();

            $table->enum('status', [
                'draft',
                'posted',
                'cancelled',
            ])->default('draft');

            $table->timestamp('received_at')->nullable();

            $table->foreignId('received_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('posted_at')->nullable();

            $table->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('notes')->nullable();

            $table->index(['purchase_order_id', 'status']);
            $table->index(['location_id', 'status']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipts');
    }
};
