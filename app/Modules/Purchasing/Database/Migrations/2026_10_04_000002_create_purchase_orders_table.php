<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->restrictOnDelete();

            $table->foreignId('location_id')
                ->constrained('locations')
                ->restrictOnDelete();

            $table->string('order_number')->unique();

            $table->enum('status', [
                'draft',
                'rfq',
                'confirmed',
                'partially_received',
                'received',
                'closed',
                'cancelled',
            ])->default('draft');

            $table->date('order_date')->nullable();
            $table->date('expected_date')->nullable();

            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('total_amount', 20, 4)->default(0);

            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('confirmed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('confirmed_at')->nullable();

            $table->index(['supplier_id', 'status']);
            $table->index(['location_id', 'status']);
            $table->index(['expected_date', 'status']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
