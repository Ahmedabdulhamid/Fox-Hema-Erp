<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 40)->unique();
            $table->string('name', 191);
            $table->enum('type', ['branch_cashbox', 'central_cashbox', 'location_cashbox', 'bank']);
            $table->enum('ownership', ['location', 'central']);
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->char('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->string('bank_name', 120)->nullable();
            $table->string('account_identifier', 120)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['location_id', 'type', 'is_active']);
        });
        Schema::create('treasury_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('idempotency_key')->unique();
            $table->char('payload_fingerprint', 64);
            $table->enum('type', ['opening_balance', 'cash_deposit', 'cash_withdrawal', 'bank_deposit', 'bank_withdrawal', 'transfer', 'payment_receipt', 'payment_disbursement', 'reversal']);
            $table->enum('status', ['draft', 'posted'])->default('draft');
            $table->foreignId('account_id')->constrained('treasury_accounts')->restrictOnDelete();
            $table->foreignId('destination_account_id')->nullable()->constrained('treasury_accounts')->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->char('currency', 3);
            $table->date('effective_date');
            $table->string('source_type', 40);
            $table->string('source_reference', 191);
            $table->text('reason');
            $table->foreignId('payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
            $table->foreignId('opening_account_id')->nullable()->unique()->constrained('treasury_accounts')->restrictOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('treasury_operations')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_reference'], 'uq_treasury_source_operation');
            $table->index(['account_id', 'status', 'effective_date'], 'idx_treasury_account_history');
            $table->index(['destination_account_id', 'status'], 'idx_treasury_destination');
        });
        Schema::create('treasury_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained('treasury_operations')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('treasury_accounts')->restrictOnDelete();
            $table->enum('direction', ['in', 'out']);
            $table->decimal('amount', 20, 4);
            $table->char('currency', 3);
            $table->enum('ownership', ['location', 'central']);
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->unique(['operation_id', 'account_id']);
            $table->index(['account_id', 'id']);
        });
        DB::statement("ALTER TABLE treasury_accounts ADD CONSTRAINT chk_treasury_ownership CHECK (
            currency = 'EGP' AND ((ownership = 'location' AND location_id IS NOT NULL) OR (ownership = 'central' AND location_id IS NULL))
            AND (type NOT IN ('branch_cashbox', 'location_cashbox') OR ownership = 'location')
            AND (type != 'central_cashbox' OR ownership = 'central')
            AND (type = 'bank' OR (bank_name IS NULL AND account_identifier IS NULL)))");
        DB::statement("ALTER TABLE treasury_operations ADD CONSTRAINT chk_treasury_operation CHECK (
            amount > 0 AND currency = 'EGP' AND (destination_account_id IS NULL OR destination_account_id != account_id)
            AND ((type = 'transfer' AND destination_account_id IS NOT NULL) OR type = 'reversal' OR destination_account_id IS NULL)
            AND ((status = 'draft' AND posted_at IS NULL AND posted_by IS NULL) OR (status = 'posted' AND posted_at IS NOT NULL AND posted_by IS NOT NULL)))");
        DB::statement("ALTER TABLE treasury_entries ADD CONSTRAINT chk_treasury_entry CHECK (
            amount > 0 AND currency = 'EGP'
            AND ((ownership = 'location' AND location_id IS NOT NULL) OR (ownership = 'central' AND location_id IS NULL)))");
        foreach (['UPDATE', 'DELETE'] as $event) {
            DB::unprepared('CREATE TRIGGER treasury_entries_no_'.strtolower($event)." BEFORE {$event} ON treasury_entries FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Treasury entries are append-only'");
        }
        DB::unprepared("CREATE TRIGGER treasury_operations_no_update BEFORE UPDATE ON treasury_operations FOR EACH ROW
            BEGIN
                IF OLD.status = 'posted' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted Treasury operations are immutable';
                END IF;
            END");
        DB::unprepared("CREATE TRIGGER treasury_operations_no_delete BEFORE DELETE ON treasury_operations FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Treasury history cannot be deleted'");
        DB::unprepared("CREATE TRIGGER treasury_entries_only_before_post BEFORE INSERT ON treasury_entries FOR EACH ROW
            BEGIN
                IF (SELECT status FROM treasury_operations WHERE id = NEW.operation_id) != 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot append to posted Treasury history';
                END IF;
            END");
        DB::unprepared("CREATE TRIGGER treasury_accounts_fixed_identity BEFORE UPDATE ON treasury_accounts FOR EACH ROW
            BEGIN
                IF NOT (OLD.code <=> NEW.code) OR NOT (OLD.type <=> NEW.type) OR NOT (OLD.ownership <=> NEW.ownership)
                    OR NOT (OLD.location_id <=> NEW.location_id) OR NOT (OLD.currency <=> NEW.currency)
                    OR NOT (OLD.uuid <=> NEW.uuid) OR NOT (OLD.created_by <=> NEW.created_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Treasury account identity is immutable';
                END IF;
            END");
    }

    public function down(): void
    {
        throw new LogicException('Treasury financial evidence requires a separately reviewed non-destructive rollback.');
    }
};
