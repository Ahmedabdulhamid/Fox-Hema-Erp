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
        Schema::create('inventory_standard_cost_revisions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('standard_cost_id')
                ->constrained('inventory_standard_costs')
                ->restrictOnDelete();

            $table->decimal('old_cost', 18, 6)
                ->nullable();

            $table->decimal('new_cost', 18, 6);

            $table->text('reason')->nullable();

            $table->foreignId('changed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('effective_at');

            $table->index(
                ['standard_cost_id', 'effective_at'],
                'idx_standard_cost_revision_history'
            );

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_standard_cost_revisions');
    }
};
