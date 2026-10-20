<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->comment('Branches and warehouses to which users can be assigned.');
            $table->id()->comment('Primary key for the location.');
            $table->string('name')->comment('Human-readable name of the location.');
            $table->string('code', 40)->unique()->comment('Unique business code of the location.');
            $table->enum('type', ['branch', 'warehouse'])->comment('Operational type of the location: branch or warehouse.');
            $table->boolean('is_active')->default(true)->comment('Whether the location is available for use.');
            $table->timestamp('created_at')->nullable()->comment('Date and time when the location was created.');
            $table->timestamp('updated_at')->nullable()->comment('Date and time when the location was last updated.');
            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
