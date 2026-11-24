<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_destinations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 100);
            $table->enum('status', ['active', 'retired'])->default('active');
            $table->string('endpoint');
            $table->string('bucket', 63);
            $table->string('prefix', 160)->default('erp-backups');
            $table->text('access_key_id');
            $table->text('secret_access_key');
            $table->unsignedInteger('configuration_version')->default(1);
            $table->timestamp('last_checked_at')->nullable();
            $table->enum('probe_status', ['not_checked', 'passed', 'failed'])->default('not_checked');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'id']);
        });
        Schema::create('backup_policies', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('destination_id')->nullable()->constrained('backup_destinations')->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('timezone', 64)->default('Africa/Cairo');
            $table->time('daily_time')->default('02:00:00');
            $table->unsignedSmallInteger('retain_daily')->default(14);
            $table->unsignedSmallInteger('retain_weekly')->default(4);
            $table->unsignedSmallInteger('retain_monthly')->default(3);
            $table->unsignedSmallInteger('minimum_verified')->default(3);
            $table->json('notification_recipients')->nullable();
            $table->timestamp('next_due_at')->nullable()->index();
            $table->unsignedInteger('configuration_version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('backup_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedTinyInteger('policy_id')->nullable();
            $table->foreign('policy_id')->references('id')->on('backup_policies')->restrictOnDelete();
            $table->foreignId('destination_id')->constrained('backup_destinations')->restrictOnDelete();
            $table->enum('trigger', ['manual', 'scheduled']);
            $table->string('idempotency_key', 100)->unique();
            $table->timestamp('scheduled_for')->nullable();
            $table->enum('status', ['queued', 'capturing', 'encrypting', 'uploading', 'verifying', 'available', 'failed', 'cancelled', 'deleting', 'deletion_failed', 'deleted'])->default('queued');
            $table->enum('verification_status', ['pending', 'checksum_verified', 'restore_verified', 'failed'])->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            foreach (['started_at', 'captured_at', 'completed_at', 'verified_at', 'deleted_at', 'retain_until'] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->string('consistency_mode', 40)->nullable();
            $table->string('application_version', 100)->nullable();
            $table->char('schema_checksum', 64)->nullable();
            $table->string('archive_format', 40)->nullable();
            $table->string('encryption_key_id', 100)->nullable();
            $table->string('object_key', 255)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('file_count')->nullable();
            $table->char('ciphertext_checksum', 64)->nullable();
            $table->char('manifest_checksum', 64)->nullable();
            $table->json('destination_snapshot')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->boolean('legal_hold')->default(false);
            $table->timestamps();
            $table->unique(['policy_id', 'scheduled_for']);
            $table->unique(['destination_id', 'object_key']);
            $table->index(['status', 'created_at']);
            $table->index(['verification_status', 'completed_at']);
            $table->index(['legal_hold', 'retain_until']);
        });
        Schema::create('backup_restore_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('backup_run_id')->constrained('backup_runs')->restrictOnDelete();
            $table->enum('mode', ['verify_only', 'production']);
            $table->enum('status', ['requested', 'verifying', 'verified', 'approved', 'restoring', 'completed', 'failed', 'cancelled'])->default('requested');
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('artifact_checksum', 64)->nullable();
            $table->string('target_identifier', 100);
            $table->json('verification_findings')->nullable();
            foreach (['approval_expires_at', 'started_at', 'completed_at'] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->string('failure_code', 80)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
        Schema::create('backup_operation_leases', function (Blueprint $table): void {
            $table->string('scope', 40)->primary();
            $table->uuid('operation_uuid')->unique();
            $table->enum('operation_type', ['backup', 'restore', 'retention', 'verification']);
            $table->string('owner_token', 100);
            $table->unsignedBigInteger('fencing_number')->default(1);
            $table->dateTime('heartbeat_at');
            $table->dateTime('expires_at')->index();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE backup_policies ADD CONSTRAINT backup_policy_singleton CHECK (id = 1 AND minimum_verified >= 1 AND minimum_verified <= retain_daily)');
        DB::statement("ALTER TABLE backup_runs ADD CONSTRAINT backup_available_evidence CHECK (status <> 'available' OR (completed_at IS NOT NULL AND verified_at IS NOT NULL AND verification_status IN ('checksum_verified', 'restore_verified') AND object_key IS NOT NULL AND size_bytes IS NOT NULL AND ciphertext_checksum IS NOT NULL AND manifest_checksum IS NOT NULL))");
        DB::statement("ALTER TABLE backup_restore_runs ADD CONSTRAINT backup_restore_approval CHECK (status NOT IN ('approved', 'restoring', 'completed') OR mode = 'verify_only' OR (approved_by IS NOT NULL AND requested_by IS NOT NULL AND approved_by <> requested_by AND artifact_checksum IS NOT NULL AND approval_expires_at IS NOT NULL))");
    }

    public function down(): void
    {
        foreach (['backup_operation_leases', 'backup_restore_runs', 'backup_runs', 'backup_policies', 'backup_destinations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
