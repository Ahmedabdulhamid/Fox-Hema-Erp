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
        // Explicit default suppresses MariaDB's legacy implicit ON UPDATE timestamp behavior.
        // Existing receipt dates are preserved; this does not guess or repair historical provenance.
        Schema::table('fifo_layers', function (Blueprint $table): void {
            $table->timestamp('received_at')->useCurrent()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('FIFO receipt provenance must not regain automatic update behavior.');
    }
};
