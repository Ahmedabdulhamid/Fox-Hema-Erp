<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->string('refund_number', 60)->unique();
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('sales_credit_note_id')->nullable()->constrained('sales_credit_notes')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->enum('status', ['draft', 'pending', 'posted', 'failed', 'cancelled'])->default('draft');
            $table->decimal('amount', 20, 4);
            $table->string('external_reference', 191)->nullable();
            $table->dateTime('refunded_at')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['payment_id', 'status']);
            $table->index(['sales_credit_note_id', 'status']);
        });

        DB::statement('ALTER TABLE payment_refunds ADD CONSTRAINT chk_payment_refund_amount CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
