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
        Schema::table('stock_movements', function (Blueprint $table): void {
            // Nullable metadata preserves legacy rows without claiming they were safely posted.
            $table->char('posting_payload_hash', 64)->nullable();
            $table->string('system_actor', 80)->nullable();
            $table->foreignId('reverses_movement_id')->nullable()->unique()
                ->constrained('stock_movements')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reverses_movement_id');
            $table->dropColumn(['posting_payload_hash', 'system_actor']);
        });
    }
};
