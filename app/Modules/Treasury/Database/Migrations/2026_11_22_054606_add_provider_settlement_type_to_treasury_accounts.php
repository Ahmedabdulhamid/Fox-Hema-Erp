<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE treasury_accounts MODIFY COLUMN type ENUM('branch_cashbox', 'central_cashbox', 'location_cashbox', 'bank', 'provider_settlement') NOT NULL");
        DB::statement("ALTER TABLE treasury_accounts ADD CONSTRAINT chk_provider_settlement_location CHECK (
            type != 'provider_settlement' OR (ownership = 'location' AND location_id IS NOT NULL))");
    }

    public function down(): void
    {
        throw new LogicException('Provider settlement account history requires a separately reviewed non-destructive rollback.');
    }
};
