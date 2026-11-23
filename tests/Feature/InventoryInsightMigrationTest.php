<?php

namespace Tests\Feature;

use App\Modules\Inventory\Services\InventoryAdjustmentDrafts;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\InventoryTestSetup;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class InventoryInsightMigrationTest extends TestCase
{
    use InventoryTestSetup;
    use UsesIsolatedDatabase;

    private const MIGRATION = '2026_10_22_103535_create_inventory_insight_settings';

    private const PATH = 'app/Modules/Inventory/Database/Migrations/'.self::MIGRATION.'.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
    }

    public function test_resumes_empty_partial_tables_adds_missing_indexes_and_records_migration_once(): void
    {
        $columns = Schema::getColumns('inventory_adjustment_drafts');
        $foreignKeys = Schema::getForeignKeys('inventory_adjustment_drafts');
        if (! Schema::hasIndex('inventory_adjustment_drafts', ['location_id'])) {
            Schema::table('inventory_adjustment_drafts', fn (Blueprint $table) => $table->index('location_id', 'inventory_adjustment_drafts_location_id_foreign'));
        }
        Schema::table('inventory_adjustment_drafts', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropIndex('inventory_count_drafts_location_variant_date');
        });
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->artisan('migrate', ['--path' => self::PATH, '--no-interaction' => true])->assertSuccessful();

        $this->assertSame($columns, Schema::getColumns('inventory_adjustment_drafts'));
        $this->assertSame($foreignKeys, Schema::getForeignKeys('inventory_adjustment_drafts'));
        $this->assertTrue(Schema::hasIndex('inventory_adjustment_drafts', ['uuid'], 'unique'));
        $this->assertTrue(Schema::hasIndex('inventory_adjustment_drafts', ['location_id', 'variant_id', 'created_at']));
        $this->assertCount(0, array_filter($queries, fn (string $sql): bool => (bool) preg_match('/^\s*(?:DROP|CREATE)\s+TABLE/i', $sql)));
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());
        $this->artisan('migrate', ['--path' => self::PATH, '--no-interaction' => true])->assertSuccessful();
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());
    }

    public function test_preserves_records_and_refuses_to_adopt_populated_tables(): void
    {
        app(InventoryAdjustmentDrafts::class)->policy($this->actor, $this->variant->id, $this->location->id, '4', 90);
        $before = DB::table('inventory_stock_policies')->get()->map(fn (object $row): array => (array) $row)->all();
        $migration = require base_path(self::PATH);

        try {
            $migration->up();
            $this->fail('Populated tables must require review.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('existing records or unexpected columns require manual schema review', $exception->getMessage());
        }
        $this->assertSame($before, DB::table('inventory_stock_policies')->get()->map(fn (object $row): array => (array) $row)->all());
    }

    public function test_refuses_unexpected_columns_before_any_ddl_or_history_change(): void
    {
        Schema::table('inventory_adjustment_drafts', fn (Blueprint $table) => $table->string('unexpected_column')->nullable());
        $before = Schema::getIndexes('inventory_stock_policies');
        $migration = require base_path(self::PATH);

        try {
            try {
                $migration->up();
                $this->fail('An incompatible table must require review.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('unexpected columns require manual schema review', $exception->getMessage());
            }
            $this->assertTrue(Schema::hasColumn('inventory_adjustment_drafts', 'unexpected_column'));
            $this->assertSame($before, Schema::getIndexes('inventory_stock_policies'));
        } finally {
            Schema::table('inventory_adjustment_drafts', fn (Blueprint $table) => $table->dropColumn('unexpected_column'));
        }
    }
}
