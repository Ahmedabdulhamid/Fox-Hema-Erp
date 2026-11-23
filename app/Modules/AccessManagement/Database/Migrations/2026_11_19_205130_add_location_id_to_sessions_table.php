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
        Schema::table('sessions', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->index(['location_id', 'last_activity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table): void {
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id', 'last_activity']);
            $table->dropColumn('location_id');
        });
    }
};
