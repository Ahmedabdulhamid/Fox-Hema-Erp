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
        // NULL preserves unresolved legacy aggregates without guessing a location.
        $legacyUnique = collect(Schema::getIndexes('inventory_cost_states'))
            ->first(fn (array $index): bool => $index['unique'] && $index['columns'] === ['variant_id']);
        if ($legacyUnique === null || Schema::hasColumn('inventory_cost_states', 'location_id')) {
            throw new LogicException('Unexpected cost-state schema. Audit before attempting this transition.');
        }
        Schema::table('inventory_cost_states', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->unsignedBigInteger('legacy_variant_id')->nullable()
                ->storedAs('case when location_id is null then variant_id else null end')
                ->unique('uq_cost_states_legacy_variant');
            $table->index('variant_id', 'idx_cost_states_variant');
            $table->unique(['variant_id', 'location_id'], 'uq_cost_states_variant_location');
        });
        Schema::table('inventory_cost_states', function (Blueprint $table) use ($legacyUnique): void {
            $table->dropUnique($legacyUnique['name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Location valuation cannot be collapsed automatically. Review a data-preserving rollback separately.');
    }
};
