<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table): void {
            $table->foreignId('cash_treasury_account_id')->nullable()->constrained('treasury_accounts')->restrictOnDelete();
            $table->char('currency', 3)->default('EGP');
            $table->uuid('opening_key')->nullable()->unique();
            $table->char('opening_payload_hash', 64)->nullable();
            $table->uuid('closing_key')->nullable()->unique();
            $table->char('closing_payload_hash', 64)->nullable();
            $table->unsignedBigInteger('active_device_id')->nullable()->storedAs("CASE WHEN status IN ('open', 'closing') THEN pos_device_id ELSE NULL END")->unique();
            $table->unsignedBigInteger('active_cashier_id')->nullable()->storedAs("CASE WHEN status IN ('open', 'closing') THEN opened_by ELSE NULL END")->unique();
            $table->unsignedBigInteger('active_cash_account_id')->nullable()->storedAs("CASE WHEN status IN ('open', 'closing') THEN cash_treasury_account_id ELSE NULL END")->unique();
        });
        Schema::table('pos_transactions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->foreignId('source_pos_transaction_id')->nullable()->constrained('pos_transactions')->restrictOnDelete();
            $table->unique(['id', 'pos_session_id', 'location_id'], 'uq_pos_transaction_session_location');
            $table->unique(['id', 'pos_session_id'], 'uq_pos_transaction_session');
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreign(['pos_transaction_id', 'pos_session_id', 'location_id'], 'fk_payment_pos_context')->references(['id', 'pos_session_id', 'location_id'])->on('pos_transactions')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payment_pos_pair CHECK ((pos_session_id IS NULL AND pos_transaction_id IS NULL) OR (pos_session_id IS NOT NULL AND pos_transaction_id IS NOT NULL))');
        Schema::table('payment_refunds', function (Blueprint $table): void {
            $table->foreignId('pos_session_id')->nullable()->constrained('pos_sessions')->restrictOnDelete();
            $table->foreignId('pos_transaction_id')->nullable()->constrained('pos_transactions')->restrictOnDelete();
            $table->foreign(['pos_transaction_id', 'pos_session_id'], 'fk_refund_pos_context')->references(['id', 'pos_session_id'])->on('pos_transactions')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE payment_refunds ADD CONSTRAINT chk_refund_pos_pair CHECK ((pos_session_id IS NULL AND pos_transaction_id IS NULL) OR (pos_session_id IS NOT NULL AND pos_transaction_id IS NOT NULL))');
        Schema::table('pos_cash_movements', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->char('payload_fingerprint', 64)->nullable();
            $table->foreignId('treasury_operation_id')->nullable()->unique()->constrained('treasury_operations')->restrictOnDelete();
        });
        Schema::table('pos_session_tenders', function (Blueprint $table): void {
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->restrictOnDelete();
            $table->unique(['pos_session_id', 'payment_method_id'], 'uq_pos_session_payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('pos_session_tenders', function (Blueprint $table): void {
            $table->dropUnique('uq_pos_session_payment_method');
            $table->dropConstrainedForeignId('payment_method_id');
        });
        Schema::table('pos_cash_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('treasury_operation_id');
            $table->dropColumn(['idempotency_key', 'payload_fingerprint']);
        });
        $this->dropCheck('payment_refunds', 'chk_refund_pos_pair');
        Schema::table('payment_refunds', function (Blueprint $table): void {
            $table->dropForeign('fk_refund_pos_context');
            $table->dropConstrainedForeignId('pos_transaction_id');
            $table->dropConstrainedForeignId('pos_session_id');
        });
        $this->dropCheck('payments', 'chk_payment_pos_pair');
        Schema::table('payments', fn (Blueprint $table) => $table->dropForeign('fk_payment_pos_context'));
        Schema::table('pos_transactions', function (Blueprint $table): void {
            $table->dropUnique('uq_pos_transaction_session_location');
            $table->dropUnique('uq_pos_transaction_session');
            $table->dropConstrainedForeignId('source_pos_transaction_id');
            $table->dropColumn('schema_version');
        });
        Schema::table('pos_sessions', function (Blueprint $table): void {
            $table->dropColumn(['active_device_id', 'active_cashier_id', 'active_cash_account_id']);
            $table->dropConstrainedForeignId('cash_treasury_account_id');
            $table->dropColumn(['currency', 'opening_key', 'opening_payload_hash', 'closing_key', 'closing_payload_hash']);
        });
    }

    private function dropCheck(string $table, string $constraint): void
    {
        $mariaDb = str_contains(strtolower(DB::selectOne('SELECT VERSION() AS version')->version), 'mariadb');
        DB::statement('ALTER TABLE '.$table.($mariaDb ? ' DROP CONSTRAINT ' : ' DROP CHECK ').$constraint);
    }
};
