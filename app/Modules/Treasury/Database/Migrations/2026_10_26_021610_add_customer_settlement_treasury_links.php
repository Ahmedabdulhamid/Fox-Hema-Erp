<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('treasury_account_id')->nullable()->constrained('treasury_accounts')->restrictOnDelete();
        });
        Schema::table('payment_refunds', function (Blueprint $table): void {
            $table->foreignId('treasury_account_id')->nullable()->constrained('treasury_accounts')->restrictOnDelete();
            $table->foreignId('treasury_operation_id')->nullable()->unique()->constrained('treasury_operations')->restrictOnDelete();
            $table->foreignId('recovery_operation_id')->nullable()->unique()->constrained('treasury_operations')->restrictOnDelete();
        });
        $drop = str_contains(strtolower(DB::selectOne('SELECT VERSION() AS version')->version), 'mariadb') ? 'DROP CONSTRAINT' : 'DROP CHECK';
        DB::statement("ALTER TABLE document_number_sequences {$drop} chk_financial_sequence_scope");
        DB::statement("ALTER TABLE document_numbers {$drop} chk_financial_number_scope");
        DB::statement("ALTER TABLE document_number_sequences ADD CONSTRAINT chk_financial_sequence_scope CHECK ((location_id IS NOT NULL AND global_scope IS NULL) OR (location_id IS NULL AND global_scope IS NOT NULL AND global_scope = document_type AND document_type IN ('TA','TCR','TCP','TTRF','TSP','TOB','TREV','TCU','TRCPT','TALLOC','TRFND')))");
        DB::statement("ALTER TABLE document_numbers ADD CONSTRAINT chk_financial_number_scope CHECK (location_id IS NOT NULL OR document_type IN ('TA','TCR','TCP','TTRF','TSP','TOB','TREV','TCU','TRCPT','TALLOC','TRFND'))");
    }

    public function down(): void
    {
        throw new LogicException('Customer financial links and numbers must be preserved.');
    }
};
