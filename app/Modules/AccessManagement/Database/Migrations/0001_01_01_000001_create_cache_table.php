<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->comment('Application cache entries.');
            $table->string('key')->primary()->comment('Unique cache item key.');
            $table->mediumText('value')->comment('Serialized cached value.');
            $table->bigInteger('expiration')->index()->comment('Unix timestamp when the cache item expires.');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->comment('Distributed locks used to coordinate cache operations.');
            $table->string('key')->primary()->comment('Unique cache lock key.');
            $table->string('owner')->comment('Identifier of the process that owns the lock.');
            $table->bigInteger('expiration')->index()->comment('Unix timestamp when the lock expires.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
