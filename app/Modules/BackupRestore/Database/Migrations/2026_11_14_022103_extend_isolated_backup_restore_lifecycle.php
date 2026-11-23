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
        Schema::table('backup_restore_runs', function (Blueprint $table): void {
            $table->char('request_key', 64)->nullable()->unique();
            $table->json('artifact_identity')->nullable();
            $table->char('owner_token', 64)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('heartbeat_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backup_restore_runs', function (Blueprint $table): void {
            $table->dropUnique(['request_key']);
            $table->dropIndex(['heartbeat_at']);
            $table->dropColumn(['request_key', 'artifact_identity', 'owner_token', 'attempts', 'heartbeat_at']);
        });
    }
};
