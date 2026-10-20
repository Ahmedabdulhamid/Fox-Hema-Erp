<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->char('submission_payload_hash', 64)->nullable();
            $table->char('posting_payload_hash', 64)->nullable();
            $table->char('posting_revision', 64)->nullable();
            $table->json('document_snapshot')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('sales_invoice_items', fn (Blueprint $table) => $table->json('commercial_snapshot')->nullable());
        Schema::create('sales_invoice_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });
        foreach (DB::table('sales_invoices')->select('invoice_number')->cursor() as $invoice) {
            if (preg_match('/^INV-(\d{4})-(\d+)$/D', $invoice->invoice_number, $match)) {
                DB::table('sales_invoice_sequences')->updateOrInsert(['year' => (int) $match[1]], [
                    'last_number' => max((int) $match[2], (int) DB::table('sales_invoice_sequences')->where('year', $match[1])->value('last_number')),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_sequences');
        Schema::table('sales_invoice_items', fn (Blueprint $table) => $table->dropColumn('commercial_snapshot'));
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['submission_payload_hash', 'posting_payload_hash', 'posting_revision', 'document_snapshot', 'cancelled_at']);
        });
    }
};
