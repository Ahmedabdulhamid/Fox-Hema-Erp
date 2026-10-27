<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->char('currency', 3)->nullable();
            $table->char('payload_fingerprint', 64)->nullable();
            $table->string('origin_module', 80)->nullable();
            $table->string('source_document_type', 80)->nullable();
            $table->string('source_document_reference', 191)->nullable();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->index(['origin_module', 'location_id', 'status'], 'idx_payments_origin_location_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Payment evidence requires a separately reviewed non-destructive rollback.');
    }
};
