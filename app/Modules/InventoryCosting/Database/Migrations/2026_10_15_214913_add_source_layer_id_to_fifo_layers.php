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
        Schema::table('fifo_layers', function (Blueprint $table): void {
            $table->foreignId('source_layer_id')->nullable()->constrained('fifo_layers')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('FIFO provenance must not be removed by automatic rollback.');
    }
};
