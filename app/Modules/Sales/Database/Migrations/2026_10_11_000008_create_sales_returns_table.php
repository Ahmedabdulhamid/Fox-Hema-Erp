<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table): void {
            $table->id();

            $table->string('return_number', 60)->unique();

            $table->foreignId('sales_order_id')
                ->nullable()
                ->constrained('sales_orders')
                ->restrictOnDelete();

            $table->foreignId('sales_invoice_id')
                ->nullable()
                ->constrained('sales_invoices')
                ->restrictOnDelete();

            $table->foreignId('location_id')
                ->constrained('locations')
                ->restrictOnDelete();

            $table->enum('status', [
                'draft',
                'approved',
                'posted',
                'cancelled',
            ])->default('draft');

            $table->date('return_date');

            $table->string('reason')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('approved_at')->nullable();

            $table->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('posted_at')->nullable();

            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('cancelled_at')->nullable();

            $table->index(['sales_order_id', 'status']);
            $table->index(['sales_invoice_id', 'status']);
            $table->index(['location_id', 'status']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_returns');
    }
};
