<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->text('address')->nullable()->after('type');
            $table->string('phone', 40)->nullable()->after('address');
        });
        Schema::table('sessions', function (Blueprint $table): void {
            $table->timestamp('login_at')->nullable()->after('user_agent');
            $table->index(['user_id', 'last_activity'], 'sessions_user_last_activity_index');
        });
        Schema::table('auth_events', function (Blueprint $table): void {
            $table->foreignId('actor_user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->after('actor_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->after('target_user_id')->constrained('locations')->nullOnDelete();
            $table->index(['target_user_id', 'occurred_at'], 'auth_events_target_time_index');
            $table->index(['location_id', 'occurred_at'], 'auth_events_location_time_index');
        });
        DB::table('auth_events')->whereNull('target_user_id')->update(['target_user_id' => DB::raw('user_id')]);
    }

    public function down(): void
    {
        // Security/audit migrations are intentionally additive and irreversible.
    }
};
