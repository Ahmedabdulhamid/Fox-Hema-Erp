<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->index()
                    ->after('remember_token')
                    ->comment('Whether the user is allowed to access the application.');
            });
        }

        if (! Schema::hasColumn('users', 'must_change_password')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('must_change_password')->default(false)
                    ->after('is_active')
                    ->comment('Whether the user must change their password at the next login.');
            });
        }

        if (! Schema::hasColumn('users', 'password_changed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('password_changed_at')->nullable()
                    ->after('must_change_password')
                    ->comment('Date and time when the password was last changed.');
            });
        }

        if (! Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('last_login_at')->nullable()
                    ->after('password_changed_at')
                    ->comment('Date and time of the user most recent successful login.');
            });
        }

        if (! Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->softDeletes()->after('last_login_at')
                    ->comment('Date and time when the user was soft deleted.');
            });
        }
    }

    public function down(): void
    {
        // Intentionally irreversible to avoid deleting authentication state from existing users.
    }
};
