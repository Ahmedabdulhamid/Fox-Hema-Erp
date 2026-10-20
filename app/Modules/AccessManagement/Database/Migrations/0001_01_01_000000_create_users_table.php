<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->comment('Application users and their authentication state.');
            $table->id()->comment('Primary key for the user.');
            $table->string('name')->comment('Display name of the user.');
            $table->string('email')->unique()->comment('Unique email address used to identify the user.');
            $table->timestamp('email_verified_at')->nullable()->comment('Date and time when the email address was verified.');
            $table->string('password')->comment('Hashed password used to authenticate the user.');
            $table->string('remember_token', 100)->nullable()->comment('Token used to keep the user signed in.');
            $table->boolean('is_active')->default(true)->index()->comment('Whether the user is allowed to access the application.');
            $table->boolean('must_change_password')->default(false)->comment('Whether the user must change their password at the next login.');
            $table->timestamp('password_changed_at')->nullable()->comment('Date and time when the password was last changed.');
            $table->timestamp('last_login_at')->nullable()->comment('Date and time of the user\'s most recent successful login.');
            $table->timestamp('deleted_at')->nullable()->comment('Date and time when the user was soft deleted.');
            $table->timestamp('created_at')->nullable()->comment('Date and time when the user was created.');
            $table->timestamp('updated_at')->nullable()->comment('Date and time when the user was last updated.');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->comment('Password reset tokens issued to users.');
            $table->string('email')->primary()->comment('Email address that requested the password reset.');
            $table->string('token')->comment('Hashed token used to authorize the password reset.');
            $table->timestamp('created_at')->nullable()->comment('Date and time when the reset token was created.');
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->comment('Server-side session records for authenticated and guest users.');
            $table->string('id')->primary()->comment('Unique session identifier.');
            $table->foreignId('user_id')->nullable()->index()->comment('User associated with the session, when authenticated.');
            $table->string('ip_address', 45)->nullable()->comment('IP address from which the session originated.');
            $table->text('user_agent')->nullable()->comment('Client user-agent string associated with the session.');
            $table->longText('payload')->comment('Serialized session data.');
            $table->integer('last_activity')->index()->comment('Unix timestamp of the most recent session activity.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
