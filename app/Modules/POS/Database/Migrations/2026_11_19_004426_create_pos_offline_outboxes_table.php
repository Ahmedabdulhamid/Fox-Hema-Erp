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
        Schema::create('pos_offline_outboxes', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->foreignId('pos_session_id')->constrained('pos_sessions')->restrictOnDelete();
            $table->json('transaction_ids')->nullable();
            $table->timestamp('sealed_at')->nullable();
            $table->timestamps();
            $table->index(['pos_session_id', 'sealed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_offline_outboxes');
    }
};
