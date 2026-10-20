<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_transactions', function (Blueprint $table): void {
            $table->id();

            $table->uuid('client_transaction_id')->unique();

            $table->foreignId('pos_device_id');

            $table->foreignId('pos_session_id');

            $table->foreignId('location_id');

            $table->foreignId('cashier_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('sales_order_id')
                ->nullable()
                ->unique()
                ->constrained('sales_orders')
                ->restrictOnDelete();

            $table->foreignId('sales_invoice_id')
                ->nullable()
                ->unique()
                ->constrained('sales_invoices')
                ->restrictOnDelete();

            $table->foreignId('sales_return_id')
                ->nullable()
                ->unique()
                ->constrained('sales_returns')
                ->restrictOnDelete();

            $table->foreignId('sales_credit_note_id')
                ->nullable()
                ->unique()
                ->constrained('sales_credit_notes')
                ->restrictOnDelete();

            $table->enum('transaction_type', [
                'sale',
                'return',
            ])->default('sale');

            $table->enum('sync_status', [
                'received',
                'processing',
                'completed',
                'rejected',
                'conflict',
            ])->default('received');

            $table->dateTime('client_occurred_at');

            $table->dateTime('server_received_at')->nullable();

            $table->dateTime('processed_at')->nullable();

            $table->char('payload_hash', 64);

            $table->json('request_payload')->nullable();

            $table->json('result_payload')->nullable();

            $table->unsignedInteger('attempts')->default(0);

            $table->dateTime('last_attempt_at')->nullable();

            $table->string('error_code', 80)->nullable();

            $table->text('error_message')->nullable();

            $table->index(['pos_device_id', 'sync_status']);

            $table->index(['location_id', 'client_occurred_at']);

            $table->index(['sync_status', 'last_attempt_at']);

            $table->foreign(
                ['pos_session_id', 'pos_device_id', 'location_id'],
                'fk_pos_transaction_session'
            )
                ->references(['id', 'pos_device_id', 'location_id'])
                ->on('pos_sessions')
                ->restrictOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_transactions');
    }
};
