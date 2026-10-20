<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_number', 60)->unique();
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('pos_session_id')->nullable()->constrained('pos_sessions')->restrictOnDelete();
            $table->foreignId('pos_transaction_id')->nullable()->constrained('pos_transactions')->restrictOnDelete();
            $table->enum('direction', ['in', 'out'])->default('in');
            $table->enum('status', ['draft', 'pending', 'posted', 'failed', 'cancelled'])->default('draft');
            $table->decimal('amount', 20, 4);
            $table->string('external_reference', 191)->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'status', 'paid_at']);
            $table->index(['payment_method_id', 'status']);
            $table->index(['pos_session_id', 'status']);
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
