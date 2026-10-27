<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertEmptyPartialTable('inventory_stock_policies', ['id', 'variant_id', 'location_id', 'reorder_threshold', 'dead_stock_days', 'updated_by', 'created_at', 'updated_at']);
        $this->assertEmptyPartialTable('inventory_adjustment_drafts', ['id', 'uuid', 'variant_id', 'location_id', 'observed_on_hand', 'observed_reserved', 'physical_quantity', 'reason', 'notes', 'status', 'payload_hash', 'created_by', 'created_at', 'updated_at']);

        if (! Schema::hasTable('inventory_stock_policies')) {
            Schema::create('inventory_stock_policies', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
                $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
                $table->decimal('reorder_threshold', 18, 4)->nullable();
                $table->unsignedSmallInteger('dead_stock_days')->default(90);
                $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->unique(['variant_id', 'location_id']);
            });
        }
        if (! Schema::hasTable('inventory_adjustment_drafts')) {
            Schema::create('inventory_adjustment_drafts', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
                $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
                $table->decimal('observed_on_hand', 18, 4);
                $table->decimal('observed_reserved', 18, 4);
                $table->decimal('physical_quantity', 18, 4);
                $table->string('reason');
                $table->text('notes')->nullable();
                $table->string('status')->default('draft');
                $table->char('payload_hash', 64);
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->index(['location_id', 'variant_id', 'created_at'], 'inventory_count_drafts_location_variant_date');
            });
        }

        if (! Schema::hasIndex('inventory_stock_policies', ['variant_id', 'location_id'], 'unique')) {
            Schema::table('inventory_stock_policies', fn (Blueprint $table) => $table->unique(['variant_id', 'location_id']));
        }
        if (! Schema::hasIndex('inventory_adjustment_drafts', ['uuid'], 'unique')) {
            Schema::table('inventory_adjustment_drafts', fn (Blueprint $table) => $table->unique('uuid'));
        }
        if (! Schema::hasIndex('inventory_adjustment_drafts', ['location_id', 'variant_id', 'created_at'])) {
            Schema::table('inventory_adjustment_drafts', fn (Blueprint $table) => $table->index(['location_id', 'variant_id', 'created_at'], 'inventory_count_drafts_location_variant_date'));
        }
    }

    /** @param list<string> $expectedColumns */
    private function assertEmptyPartialTable(string $table, array $expectedColumns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $columns = Schema::getColumnListing($table);
        sort($columns);
        sort($expectedColumns);
        if ($columns !== $expectedColumns || DB::table($table)->exists()) {
            throw new RuntimeException('Refusing to adopt '.$table.': existing records or unexpected columns require manual schema review.');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustment_drafts');
        Schema::dropIfExists('inventory_stock_policies');
    }
};
