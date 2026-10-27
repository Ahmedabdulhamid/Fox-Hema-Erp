<?php

use App\Modules\Treasury\Services\FinancialNumberingConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        app(FinancialNumberingConstraints::class)->ensure(['TCU', 'TRCPT', 'TALLOC', 'TRFND', 'TPM']);
        if (DB::selectOne('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?', ['payment_methods_fixed_identity']) === null) {
            DB::unprepared("CREATE TRIGGER payment_methods_fixed_identity BEFORE UPDATE ON payment_methods FOR EACH ROW
                BEGIN
                    IF NOT (OLD.code <=> NEW.code) OR NOT (OLD.type <=> NEW.type) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payment method identities are immutable';
                    END IF;
                END");
        }
    }

    public function down(): void
    {
        throw new LogicException('Allocated payment-method numbers must be preserved.');
    }
};
