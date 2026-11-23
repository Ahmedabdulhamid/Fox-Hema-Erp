<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_write_barriers', function (Blueprint $table): void {
            $table->string('scope', 40)->primary();
            $table->enum('state', ['open', 'draining', 'capturing', 'recovery_required'])->default('open');
            $table->unsignedBigInteger('fence')->default(0);
            $table->uuid('run_uuid')->nullable();
            $table->unsignedBigInteger('lease_fence')->nullable();
            $table->char('owner_hash', 64)->nullable();
            $table->json('process_identity')->nullable();
            $table->dateTime('heartbeat_at', 6)->nullable();
            $table->dateTime('deadline_at', 6)->nullable()->index();
            $table->string('failure_code', 80)->nullable();
        });
        Schema::create('backup_write_operations', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->char('owner_hash', 64);
            $table->enum('kind', ['http', 'queue', 'console', 'service']);
            $table->unsignedBigInteger('admitted_fence');
            $table->dateTime('started_at', 6)->index();
            $table->dateTime('heartbeat_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_write_operations');
        Schema::dropIfExists('backup_write_barriers');
    }
};
