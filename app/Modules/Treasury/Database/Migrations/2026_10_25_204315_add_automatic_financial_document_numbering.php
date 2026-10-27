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
        Schema::table('document_number_sequences', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->change();
            $table->string('global_scope', 8)->nullable()->unique();
        });
        Schema::table('document_numbers', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->change();
        });
        Schema::table('treasury_operations', function (Blueprint $table): void {
            $table->string('code', 40)->nullable()->unique();
        });
        DB::statement("ALTER TABLE document_number_sequences ADD CONSTRAINT chk_financial_sequence_scope CHECK (
            (location_id IS NOT NULL AND global_scope IS NULL) OR
            (location_id IS NULL AND global_scope IS NOT NULL AND global_scope = document_type AND document_type IN ('TA','TCR','TCP','TTRF','TSP','TOB','TREV'))
        )");
        DB::statement("ALTER TABLE document_numbers ADD CONSTRAINT chk_financial_number_scope CHECK (
            location_id IS NOT NULL OR document_type IN ('TA','TCR','TCP','TTRF','TSP','TOB','TREV')
        )");
        DB::unprepared("CREATE TRIGGER treasury_operations_fixed_code BEFORE UPDATE ON treasury_operations FOR EACH ROW
            BEGIN
                IF NOT (OLD.code <=> NEW.code) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Generated Treasury codes are immutable';
                END IF;
            END");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Allocated financial numbers must be preserved.');
    }
};
