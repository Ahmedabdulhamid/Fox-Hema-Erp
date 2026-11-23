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
        Schema::table('backup_policies', function (Blueprint $table): void {
            $table->string('schedule_frequency', 10)->default('daily');
            $table->unsignedTinyInteger('schedule_weekday')->default(0);
            $table->boolean('schedule_requested')->default(false);
            $table->dateTime('schedule_requested_at')->nullable();
            $table->boolean('retention_requested')->default(false);
            $table->unsignedSmallInteger('retention_days')->default(90);
            $table->unsignedSmallInteger('maximum_backups')->default(30);
        });
        Schema::create('backup_schedule_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('policy_id');
            $table->foreign('policy_id')->references('id')->on('backup_policies')->restrictOnDelete();
            $table->date('civil_date');
            $table->dateTime('scheduled_for');
            $table->unsignedInteger('policy_version');
            $table->string('status', 24)->default('pending');
            $table->uuid('run_uuid')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestamps();
            $table->unique(['policy_id', 'civil_date']);
        });
        Schema::create('backup_deletions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backup_run_id')->unique()->constrained('backup_runs')->restrictOnDelete();
            $table->string('status', 24);
            $table->char('owner_token', 64);
            $table->unsignedBigInteger('fence');
            $table->unsignedInteger('attempts')->default(1);
            $table->json('object_identity');
            $table->string('etag')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('backup_alerts', function (Blueprint $table): void {
            $table->id();
            $table->char('deduplication_key', 64)->unique();
            $table->string('event', 40);
            $table->uuid('run_uuid')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->dateTime('last_seen_at');
            $table->timestamps();
        });
        Schema::create('backup_alert_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_id')->constrained('backup_alerts')->restrictOnDelete();
            $table->char('recipient_hash', 64);
            $table->string('status', 16);
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['alert_id', 'recipient_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['backup_alert_deliveries', 'backup_alerts', 'backup_deletions', 'backup_schedule_occurrences'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('backup_policies', function (Blueprint $table): void {
            $table->dropColumn(['schedule_frequency', 'schedule_weekday', 'schedule_requested', 'schedule_requested_at', 'retention_requested', 'retention_days', 'maximum_backups']);
        });
    }
};
