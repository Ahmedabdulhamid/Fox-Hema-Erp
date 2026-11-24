<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\StockBalance;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\UsesStockConstraintSchema;
use Tests\TestCase;

class StockBalanceConstraintMigrationTest extends TestCase
{
    use UsesStockConstraintSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStockSchemaIdentity();
        foreach (['locations', 'product_variants'] as $table) {
            Schema::create($table, fn (Blueprint $blueprint) => $blueprint->id());
            DB::table($table)->insert(['id' => 1]);
        }
        (require base_path('app/Modules/Inventory/Database/Migrations/2026_10_09_000015_create_stock_balances_table.php'))->up();
        Schema::table('stock_balances', fn (Blueprint $table) => $table->uuid('uuid')->nullable()->unique());
    }

    /** @return list<string> */
    private function checks(): array
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'stock_balances')->where('CONSTRAINT_TYPE', 'CHECK')->orderBy('CONSTRAINT_NAME')->pluck('CONSTRAINT_NAME')->all();
    }

    /** @return array{variant_id: int, location_id: int, quantity_on_hand: string, quantity_reserved: string, quantity_available: string} */
    private function row(string $hand = '5', string $reserved = '2', string $available = '3'): array
    {
        return ['variant_id' => 1, 'location_id' => 1, 'quantity_on_hand' => $hand,
            'quantity_reserved' => $reserved, 'quantity_available' => $available];
    }

    private function rejectCheck(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid stock arithmetic must fail at the database boundary.');
        } catch (QueryException $exception) {
            $maria = str_contains(DB::selectOne('SELECT VERSION() AS version')->version, 'MariaDB');
            $this->assertSame($maria ? 4025 : 3819, (int) $exception->errorInfo[1]);
            $this->assertStringContainsString('chk_stock_balances_', $exception->getMessage());
        }
    }

    private function rejectMigration(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('Unsafe migration must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function test_empty_install_replay_and_up_down_up_preserve_rows_indexes_and_foreign_keys(): void
    {
        $indexes = Schema::getIndexes('stock_balances');
        $foreignKeys = Schema::getForeignKeys('stock_balances');
        $migration = $this->stockBalanceMigration();
        $migration->up();
        $this->assertCount(4, $this->checks());
        DB::table('stock_balances')->insert($this->row());
        $before = $this->stockTableEvidence(['stock_balances']);
        $migration->up();
        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT unrelated_stock_check CHECK (quantity_on_hand < 100)');
        $migration->down();
        $migration->down();
        $this->assertSame(['unrelated_stock_check'], $this->checks());
        $migration->up();
        $this->assertCount(5, $this->checks());
        $this->assertSame($before, $this->stockTableEvidence(['stock_balances']));
        $this->assertSame($indexes, Schema::getIndexes('stock_balances'));
        $this->assertSame($foreignKeys, Schema::getForeignKeys('stock_balances'));
        if (! str_contains(DB::selectOne('SELECT VERSION() AS version')->version, 'MariaDB')) {
            $this->assertSame(4, DB::table('information_schema.TABLE_CONSTRAINTS')->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'stock_balances')->where('CONSTRAINT_NAME', 'like', 'chk_stock_balances_%')->where('ENFORCED', 'YES')->count());
        } else {
            $settings = DB::selectOne('SELECT @@SESSION.check_constraint_checks AS session_checks, @@GLOBAL.check_constraint_checks AS global_checks');
            $this->assertSame(1, (int) $settings->session_checks);
            $this->assertSame(1, (int) $settings->global_checks);
        }
    }

    public function test_valid_existing_rows_defaults_and_decimal_boundaries_are_accepted(): void
    {
        DB::table('stock_balances')->insert($this->row());
        $before = $this->stockTableEvidence(['stock_balances']);
        $this->stockBalanceMigration()->up();
        $this->assertSame($before, $this->stockTableEvidence(['stock_balances']));
        foreach ([['0', '0', '0'], ['1', '1', '0'], ['0.0001', '0', '0.0001'],
            ['99999999999999.9999', '0', '99999999999999.9999'], ['99999999999999.9999', '99999999999999.9999', '0'],
            ['99999999999999.9999', '0.0001', '99999999999999.9998']] as [$hand, $reserved, $available]) {
            DB::table('stock_balances')->delete();
            $balance = StockBalance::create($this->row($hand, $reserved, $available));
            $this->assertSame((string) BigDecimal::of($hand)->toScale(4), $balance->fresh()->quantity_on_hand);
            $this->assertSame((string) BigDecimal::of($available)->toScale(4), $balance->fresh()->quantity_available);
        }
        DB::table('stock_balances')->delete();
        $balance = StockBalance::create(['variant_id' => 1, 'location_id' => 1]);
        $this->assertSame('0.0000', $balance->fresh()->quantity_available);
    }

    /** @return array<string, array{string, string, string}> */
    public static function invalidStates(): array
    {
        return ['negative on hand' => ['-1', '0', '-1'], 'negative reserved' => ['1', '-1', '2'],
            'over reserved' => ['1', '2', '-1'], 'available mismatch' => ['1', '0', '2']];
    }

    #[DataProvider('invalidStates')]
    public function test_raw_and_eloquent_inserts_and_updates_reject_bad_arithmetic(string $hand, string $reserved, string $available): void
    {
        $this->stockBalanceMigration()->up();
        $bad = $this->row($hand, $reserved, $available);
        $this->rejectCheck(fn () => DB::table('stock_balances')->insert($bad));
        $this->rejectCheck(fn () => StockBalance::create($bad));
        $this->assertDatabaseCount('stock_balances', 0);
        $balance = StockBalance::create($this->row());
        $before = $this->stockTableEvidence(['stock_balances']);
        $this->rejectCheck(fn () => DB::table('stock_balances')->where('id', $balance->id)->update($bad));
        $this->rejectCheck(fn () => $balance->fill($bad)->save());
        $this->assertSame($before, $this->stockTableEvidence(['stock_balances']));
    }

    #[DataProvider('invalidStates')]
    public function test_invalid_history_blocks_migration_without_repair_or_partial_checks(string $hand, string $reserved, string $available): void
    {
        $id = DB::table('stock_balances')->insertGetId($this->row($hand, $reserved, $available));
        $before = $this->stockTableEvidence(['stock_balances']);
        $this->rejectMigration(fn () => $this->stockBalanceMigration()->up(), '1 invalid historical rows; sample IDs: '.$id);
        $this->assertSame([], $this->checks());
        $this->assertSame($before, $this->stockTableEvidence(['stock_balances']));
    }

    public function test_correct_partial_install_is_completed_without_replacing_existing_constraints(): void
    {
        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT chk_stock_balances_on_hand_nonnegative CHECK (quantity_on_hand >= 0)');
        DB::table('stock_balances')->insert($this->row());
        $before = $this->stockTableEvidence(['stock_balances']);
        $options = ['--path' => self::STOCK_CONSTRAINT_MIGRATION, '--force' => true, '--no-interaction' => true];
        $this->artisan('migrate', $options)->assertSuccessful();
        $this->assertCount(4, $this->checks());
        $this->assertSame($before, $this->stockTableEvidence(['stock_balances']));
        $name = pathinfo(self::STOCK_CONSTRAINT_MIGRATION, PATHINFO_FILENAME);
        $this->assertSame(1, DB::table('migrations')->where('migration', $name)->count());
        $this->artisan('migrate', $options)->assertSuccessful();
        $this->assertSame(1, DB::table('migrations')->where('migration', $name)->count());
    }

    public function test_conflicting_partial_predicate_is_never_replaced_or_removed(): void
    {
        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT chk_stock_balances_available_matches CHECK (quantity_available = quantity_on_hand + quantity_reserved)');
        $before = DB::select('SHOW CREATE TABLE stock_balances');
        $this->rejectMigration(fn () => $this->stockBalanceMigration()->up(), 'Conflicting or unenforced');
        $this->rejectMigration(fn () => $this->stockBalanceMigration()->down(), 'Conflicting or unenforced');
        $this->assertEquals($before, DB::select('SHOW CREATE TABLE stock_balances'));
    }

    public function test_owned_name_on_another_table_blocks_installation(): void
    {
        Schema::table('locations', fn (Blueprint $table) => $table->integer('probe')->default(1));
        DB::statement('ALTER TABLE locations ADD CONSTRAINT chk_stock_balances_on_hand_nonnegative CHECK (probe > 0)');
        $this->rejectMigration(fn () => $this->stockBalanceMigration()->up(), 'Conflicting or unenforced');
        $this->assertSame([], $this->checks());
    }

    public function test_dirty_partial_install_is_left_unchanged_and_partial_rollback_preserves_data(): void
    {
        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT chk_stock_balances_on_hand_nonnegative CHECK (quantity_on_hand >= 0)');
        DB::table('stock_balances')->insert($this->row('1', '2', '-1'));
        $before = $this->stockTableEvidence(['stock_balances']);
        $this->rejectMigration(fn () => $this->stockBalanceMigration()->up(), 'invalid historical rows');
        $this->assertCount(1, $this->checks());
        $this->assertSame($before, $this->stockTableEvidence(['stock_balances']));
        $this->stockBalanceMigration()->down();
        $this->assertSame([], $this->checks());
        $this->assertSame($before, $this->stockTableEvidence(['stock_balances']));
    }

    public function test_unenforced_metadata_is_rejected_without_disabling_real_checks(): void
    {
        $manager = DB::getFacadeRoot();
        $connection = DB::connection();
        $mock = DB::partialMock();
        $mock->shouldReceive('getDriverName')->andReturn('mysql');
        $mock->shouldReceive('connection')->andReturn($connection);
        $mock->shouldReceive('selectOne')->andReturnUsing(fn (string $sql) => $manager->selectOne($sql));
        $mock->shouldReceive('select')->andReturnUsing(function (string $sql, array $bindings = []) use ($manager): array {
            if (str_contains($sql, 'LEFT JOIN information_schema.CHECK_CONSTRAINTS')) {
                return [(object) ['CONSTRAINT_NAME' => 'chk_stock_balances_on_hand_nonnegative', 'TABLE_NAME' => 'stock_balances',
                    'CONSTRAINT_TYPE' => 'CHECK', 'CHECK_CLAUSE' => 'quantity_on_hand >= 0', 'enforced' => 'NO']];
            }

            return $manager->select($sql, $bindings);
        });
        try {
            $this->rejectMigration(fn () => $this->stockBalanceMigration()->up(), 'Conflicting or unenforced');
        } finally {
            DB::swap($manager);
        }
        $this->assertSame([], $this->checks());
    }

    /** @return array<string, array{string}> */
    public static function columnDrift(): array
    {
        return ['nullable' => ['DECIMAL(18,4) NULL DEFAULT 0'], 'precision' => ['DECIMAL(19,4) NOT NULL DEFAULT 0'],
            'unsigned' => ['DECIMAL(18,4) UNSIGNED NOT NULL DEFAULT 0'], 'default' => ['DECIMAL(18,4) NOT NULL DEFAULT 1']];
    }

    #[DataProvider('columnDrift')]
    public function test_column_drift_blocks_installation_before_any_constraints_are_added(string $definition): void
    {
        DB::statement('ALTER TABLE stock_balances MODIFY quantity_on_hand '.$definition);
        $this->rejectMigration(fn () => $this->stockBalanceMigration()->up(), 'Unexpected stock balance column definition');
        $this->assertSame([], $this->checks());
    }

    public function test_ddl_inside_an_application_transaction_is_rejected_without_committing_it(): void
    {
        DB::beginTransaction();
        try {
            DB::table('stock_balances')->insert($this->row());
            $this->rejectMigration(fn () => $this->stockBalanceMigration()->up(), 'outside an application transaction');
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_multirow_failure_and_late_transaction_failure_leave_stock_unchanged(): void
    {
        $this->stockBalanceMigration()->up();
        DB::table('product_variants')->insert(['id' => 2]);
        $other = array_replace($this->row('1', '0', '2'), ['variant_id' => 2]);
        $this->rejectCheck(fn () => DB::table('stock_balances')->insert([$this->row(), $other]));
        $this->assertDatabaseCount('stock_balances', 0);
        try {
            DB::transaction(function (): void {
                StockBalance::create($this->row());
                throw new RuntimeException('Injected failure');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_custom_prefix_cannot_redirect_preflight_away_from_the_ddl_target(): void
    {
        $connection = DB::connection();
        $connection->setTablePrefix('unexpected_');
        try {
            $this->rejectMigration(fn () => $this->stockBalanceMigration()->up(), 'unprefixed project schema');
        } finally {
            $connection->setTablePrefix('');
        }
        $this->assertSame([], $this->checks());
    }

    public function test_view_is_not_adopted_as_an_innodb_balance_table(): void
    {
        Schema::drop('stock_balances');
        DB::statement('CREATE VIEW stock_balances AS SELECT 0 AS quantity_on_hand, 0 AS quantity_reserved, 0 AS quantity_available');
        $this->rejectMigration(fn () => $this->stockBalanceMigration()->up(), 'existing InnoDB stock_balances table');
    }

    public function test_null_and_decimal_overflow_are_rejected_separately_from_arithmetic_checks(): void
    {
        $this->stockBalanceMigration()->up();
        foreach ([['quantity_on_hand' => null], ['quantity_reserved' => null], ['quantity_available' => null],
            ['quantity_on_hand' => '100000000000000.0000']] as $fields) {
            try {
                DB::table('stock_balances')->insert(array_replace($this->row(), $fields));
                $this->fail('Invalid stored quantity must fail.');
            } catch (QueryException $exception) {
                $this->assertContains((int) $exception->errorInfo[1], [1048, 1264]);
            }
        }
        $this->assertDatabaseCount('stock_balances', 0);
    }

    /** @return array<string, array{string}> */
    public static function unsupportedVersions(): array
    {
        return [
            'mysql 5.7' => ['5.7.44'],
            'ubuntu mysql before enforcement' => ['8.0.15-0ubuntu0.24.04.5'],
            'mariadb before enforcement' => ['10.3.9-MariaDB'],
            'mariadb compatibility prefix before enforcement' => ['5.5.5-10.3.9-MariaDB'],
            'unparseable mysql' => ['8.0'],
            'unparseable mariadb' => ['MariaDB 10.3.10'],
        ];
    }

    #[DataProvider('unsupportedVersions')]
    public function test_unsupported_server_responses_fail_before_ddl(string $version): void
    {
        $manager = DB::getFacadeRoot();
        $connection = DB::connection();
        $mock = DB::partialMock();
        $mock->shouldReceive('getDriverName')->andReturn('mysql');
        $mock->shouldReceive('connection')->andReturn($connection);
        $mock->shouldReceive('selectOne')->with('SELECT VERSION() AS version')
            ->andReturn((object) ['version' => $version]);
        try {
            $this->rejectMigration(fn () => $this->stockBalanceMigration()->up(), 'Unsupported stock balance CHECK server');
        } finally {
            DB::swap($manager);
        }
        $this->assertSame([], $this->checks());
    }

    public function test_unsupported_driver_fails_before_connecting_to_another_engine(): void
    {
        $manager = DB::getFacadeRoot();
        DB::partialMock()->shouldReceive('getDriverName')->andReturn('sqlite');
        try {
            $this->rejectMigration(fn () => $this->stockBalanceMigration()->up(), 'requires MySQL');
        } finally {
            DB::swap($manager);
        }
        $this->assertSame([], $this->checks());
    }

    public function test_disabled_mariadb_setting_response_is_rejected_without_disabling_any_real_checks(): void
    {
        $manager = DB::getFacadeRoot();
        $connection = DB::connection();
        $mock = DB::partialMock();
        $mock->shouldReceive('getDriverName')->andReturn('mysql');
        $mock->shouldReceive('connection')->andReturn($connection);
        $mock->shouldReceive('selectOne')->with('SELECT VERSION() AS version')
            ->andReturn((object) ['version' => '10.4.32-MariaDB']);
        $mock->shouldReceive('selectOne')->with('SELECT @@SESSION.check_constraint_checks AS session_checks, @@GLOBAL.check_constraint_checks AS global_checks')
            ->andReturn((object) ['session_checks' => 0, 'global_checks' => 1]);
        try {
            $this->rejectMigration(fn () => $this->stockBalanceMigration()->up(), 'must already be enabled');
        } finally {
            DB::swap($manager);
        }
        $this->assertSame([], $this->checks());
    }
}
