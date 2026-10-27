<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 40)->unique();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('name', 191);
            $table->string('phone', 60)->nullable();
            $table->string('email', 191)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['location_id', 'name']);
        });
        Schema::create('customer_invoice_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained('payment_customers')->restrictOnDelete();
            $table->foreignId('sales_invoice_id')->unique()->constrained('sales_invoices')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('settlement_terms', 12)->nullable();
            $table->decimal('planned_immediate_amount', 20, 4)->nullable();
            $table->date('due_date')->nullable();
            $table->uuid('operation_key')->unique();
            $table->char('payload_fingerprint', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->constrained('payment_customers')->restrictOnDelete();
            $table->date('payment_date')->nullable();
            $table->index(['customer_id', 'status']);
        });
        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->index(['payment_id', 'sales_invoice_id']);
        });
        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->dropUnique('uq_payment_invoice_allocation');
            $table->string('code', 40)->nullable()->unique();
            $table->uuid('operation_key')->nullable()->unique();
            $table->char('payload_fingerprint', 64)->nullable();
            $table->foreignId('allocated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('allocated_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('release_key')->nullable()->unique();
            $table->char('release_fingerprint', 64)->nullable();
            $table->text('release_reason')->nullable();
        });
        Schema::table('payment_refunds', function (Blueprint $table): void {
            $table->char('payload_fingerprint', 64)->nullable();
            $table->date('refund_date')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->foreignId('recovered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('recovery_key')->nullable()->unique();
            $table->char('recovery_fingerprint', 64)->nullable();
            $table->text('recovery_reason')->nullable();
            $table->string('recovery_reference', 191)->nullable();
        });
    }

    public function down(): void
    {
        throw new LogicException('Customer settlement evidence must be preserved.');
    }
};
