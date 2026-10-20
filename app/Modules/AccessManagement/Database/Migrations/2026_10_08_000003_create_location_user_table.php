<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_user', function (Blueprint $table) {
            $table->comment('Assignments linking users to the locations they may access.');
            $table->id()->comment('Primary key for the user-location assignment.');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->comment('User granted access to the location.');
            $table->foreignId('location_id')->constrained()->restrictOnDelete()->comment('Location the user is allowed to access.');
            $table->timestamp('created_at')->nullable()->comment('Date and time when the assignment was created.');
            $table->timestamp('updated_at')->nullable()->comment('Date and time when the assignment was last updated.');
            $table->unique(['user_id', 'location_id']);
            $table->index(['location_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_user');
    }
};
