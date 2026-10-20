<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_events', function (Blueprint $table) {
            $table->comment('Audit trail of authentication and session-related events.');
            $table->id()->comment('Primary key for the authentication event.');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()->comment('User associated with the event, when known.');
            $table->string('event', 60)->comment('Event type, such as login, logout, login_failed, or password_changed.');
            $table->string('session_id', 255)->nullable()->comment('Session associated with the authentication event.');
            $table->ipAddress('ip_address')->nullable()->comment('IP address from which the event originated.');
            $table->text('user_agent')->nullable()->comment('Client user-agent string associated with the event.');
            $table->json('metadata')->nullable()->comment('Additional structured context for the event.');
            $table->timestamp('occurred_at')->useCurrent()->comment('Date and time when the event occurred.');
            $table->index(['user_id', 'occurred_at']);
            $table->index(['event', 'occurred_at']);
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_events');
    }
};
