<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_credit_notes', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->char('submission_payload_hash', 64)->nullable();
            $table->char('posting_revision', 64)->nullable();
            $table->char('posting_payload_hash', 64)->nullable();
            $table->json('document_snapshot')->nullable();
            $table->index(['location_id', 'status']);
        });
        Schema::table('sales_credit_note_items', function (Blueprint $table): void {
            $table->foreignId('sales_return_posted_allocation_id')->nullable()->constrained('sales_return_posted_allocations', indexName: 'fk_credit_posted_allocation')->restrictOnDelete();
            $table->json('commercial_snapshot')->nullable();
            $table->unique(['sales_credit_note_id', 'sales_return_posted_allocation_id'], 'uq_credit_return_allocation');
        });
    }

    public function down(): void
    {
        Schema::table('sales_credit_note_items', function (Blueprint $table): void {
            $table->dropUnique('uq_credit_return_allocation');
            $table->dropForeign('fk_credit_posted_allocation');
            $table->dropColumn('sales_return_posted_allocation_id');
            $table->dropColumn('commercial_snapshot');
        });
        Schema::table('sales_credit_notes', function (Blueprint $table): void {
            $table->dropIndex(['location_id', 'status']);
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn(['submission_payload_hash', 'posting_revision', 'posting_payload_hash', 'document_snapshot']);
        });
    }
};
