<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_payments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->restrictOnDelete();

            $table->string('payment_number')->unique();

            $table->date('payment_date');

            $table->decimal('amount', 20, 4);

            $table->string('method', 50);

            $table->string('external_reference')->nullable();

            $table->enum('status', [
                'draft',
                'posted',
                'void',
            ])->default('draft');

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('posted_at')->nullable();

            $table->foreignId('voided_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('voided_at')->nullable();

            $table->text('notes')->nullable();

            $table->index(['supplier_id', 'status']);
            $table->index(['payment_date', 'status']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payments');
    }
};
