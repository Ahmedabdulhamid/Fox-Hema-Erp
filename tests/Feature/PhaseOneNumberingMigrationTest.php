<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Treasury\Services\FinancialNumberingConstraints;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class PhaseOneNumberingMigrationTest extends TestCase
{
    use UsesIsolatedDatabase;

    private const TYPES = ['TA', 'TCR', 'TCP', 'TTRF', 'TSP', 'TOB', 'TREV', 'TCU', 'TRCPT', 'TALLOC', 'TRFND', 'TPM'];

    private const CHECKS = ['document_number_sequences' => 'chk_financial_sequence_scope', 'document_numbers' => 'chk_financial_number_scope'];

    private function rerunMigrations(): void
    {
        foreach (['2026_10_26_030528_repair_missing_phase_seven_customer_settlement_schema.php',
            '2026_10_26_032802_add_payment_method_document_numbering.php'] as $file) {
            (require app_path('Modules/Treasury/Database/Migrations/'.$file))->up();
        }
    }

    /** @return array<string, array{rows: string, indexes: array, foreign_keys: array}> */
    private function evidence(): array
    {
        $evidence = [];
        foreach (['document_number_sequences', 'document_numbers', 'payments', 'payment_allocations', 'payment_refunds'] as $table) {
            $evidence[$table] = ['rows' => DB::table($table)->orderBy('id')->get()->toJson(),
                'indexes' => Schema::getIndexes($table), 'foreign_keys' => Schema::getForeignKeys($table)];
        }

        return $evidence;
    }

    private function replaceCheck(string $table, ?string $predicate): void
    {
        $name = self::CHECKS[$table];
        $exists = DB::selectOne('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?', [$table, $name]) !== null;
        $drop = str_contains(DB::selectOne('SELECT VERSION() AS version')->version, 'MariaDB') ? 'DROP CONSTRAINT' : 'DROP CHECK';
        $parts = $exists ? ["{$drop} {$name}"] : [];
        if ($predicate !== null) {
            $parts[] = "ADD CONSTRAINT {$name} CHECK ({$predicate})";
        }
        DB::statement('ALTER TABLE '.$table.' '.implode(', ', $parts));
    }

    private function predicate(string $table, array $types): string
    {
        $allowed = "'".implode("','", $types)."'";

        return $table === 'document_number_sequences'
            ? "(location_id IS NOT NULL AND global_scope IS NULL) OR (location_id IS NULL AND global_scope IS NOT NULL AND global_scope=document_type AND document_type IN ({$allowed}))"
            : "location_id IS NOT NULL OR document_type IN ({$allowed})";
    }

    public function test_fresh_schema_and_repeated_migrations_preserve_financial_evidence_and_sequence_values(): void
    {
        app(ModuleRegistry::class)->register();
        Payment::factory()->create();
        foreach (array_keys(DocumentNumberService::FINANCIAL_SOURCES) as $prefix) {
            $this->assertStringStartsWith($prefix.'-', DB::transaction(fn (): string => app(DocumentNumberService::class)->allocateFinancial($prefix, (string) Str::uuid7())));
        }
        DB::table('document_number_sequences')->update(['last_number' => 73]);
        $before = $this->evidence();
        $this->rerunMigrations();
        $this->rerunMigrations();
        $this->assertSame($before, $this->evidence());
        $this->assertSame('TR-000074', DB::transaction(fn (): string => app(DocumentNumberService::class)->allocateFinancial('TR', (string) Str::uuid7())));
    }

    /** @return array<string, array{string}> */
    public static function interruptedTables(): array
    {
        return ['sequences missing' => ['document_number_sequences'], 'numbers missing' => ['document_numbers']];
    }

    #[DataProvider('interruptedTables')]
    public function test_missing_constraint_after_interruption_is_restored_without_resetting_data(string $table): void
    {
        DB::table('document_number_sequences')->insert(['location_id' => null, 'document_type' => 'TPM', 'global_scope' => 'TPM', 'location_code' => 'PM', 'last_number' => 73]);
        $before = $this->evidence();
        $this->replaceCheck($table, null);
        $this->rerunMigrations();
        $this->assertSame($before, $this->evidence());
        $this->assertSame(1, (int) DB::selectOne('SELECT COUNT(*) AS total FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?', [$table, self::CHECKS[$table]])->total);
    }

    public function test_legacy_constraints_are_widened_without_losing_either_ownership_mode(): void
    {
        $base = array_slice(self::TYPES, 0, 7);
        foreach (self::CHECKS as $table => $name) {
            $this->replaceCheck($table, $this->predicate($table, $base));
        }
        $this->rerunMigrations();
        foreach (self::TYPES as $type) {
            DB::table('document_number_sequences')->insert(['location_id' => null, 'document_type' => $type,
                'global_scope' => $type, 'location_code' => 'TEST', 'last_number' => 73]);
            DB::table('document_numbers')->insert(['location_id' => null, 'document_type' => $type,
                'document_uuid' => (string) Str::uuid7(), 'number' => 'GLOBAL-'.$type, 'sequence_value' => 73, 'created_at' => now()]);
        }
        $branch = Location::create(['name' => 'Numbering branch', 'type' => 'branch', 'is_active' => true]);
        DB::table('document_number_sequences')->insert(['location_id' => $branch->id, 'document_type' => 'INV', 'global_scope' => null, 'location_code' => $branch->code, 'last_number' => 73]);
        foreach ([
            ['location_id' => null, 'document_type' => 'INV', 'global_scope' => null],
            ['location_id' => $branch->id, 'document_type' => 'INV', 'global_scope' => 'LOCAL'],
            ['location_id' => null, 'document_type' => 'TA', 'global_scope' => 'WRONG'],
            ['location_id' => null, 'document_type' => 'INV', 'global_scope' => 'INV'],
        ] as $invalid) {
            try {
                DB::table('document_number_sequences')->insert($invalid + ['location_code' => 'TEST', 'last_number' => 0]);
                $this->fail('Invalid numbering ownership was accepted.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('chk_financial_sequence_scope', $exception->getMessage());
            }
        }
        try {
            DB::table('document_numbers')->insert(['location_id' => null, 'document_type' => 'INV', 'document_uuid' => (string) Str::uuid7(),
                'number' => 'INVALID-GLOBAL', 'sequence_value' => 1, 'created_at' => now()]);
            $this->fail('A branch document was accepted without its location.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('chk_financial_number_scope', $exception->getMessage());
        }
    }

    public function test_payment_method_migration_restores_both_missing_checks_with_all_preceding_types(): void
    {
        foreach (self::CHECKS as $table => $name) {
            $this->replaceCheck($table, null);
        }
        (require app_path('Modules/Treasury/Database/Migrations/2026_10_26_032802_add_payment_method_document_numbering.php'))->up();
        foreach (self::TYPES as $type) {
            DB::table('document_number_sequences')->insert(['location_id' => null, 'document_type' => $type,
                'global_scope' => $type, 'location_code' => 'TEST', 'last_number' => 73]);
            DB::table('document_numbers')->insert(['location_id' => null, 'document_type' => $type,
                'document_uuid' => (string) Str::uuid7(), 'number' => 'RESTORED-'.$type, 'sequence_value' => 73, 'created_at' => now()]);
        }
        $this->assertSame(count(self::TYPES), DB::table('document_number_sequences')->count());
        $this->assertSame(count(self::TYPES), DB::table('document_numbers')->count());
    }

    public function test_historical_or_extended_metadata_preserves_all_previously_allowed_types(): void
    {
        foreach (self::CHECKS as $table => $name) {
            $ownership = $table === 'document_number_sequences'
                ? 'location_id IS NULL AND global_scope IS NOT NULL AND global_scope=document_type AND '
                : 'location_id IS NULL AND ';
            $this->replaceCheck($table, '('.$this->predicate($table, array_slice(self::TYPES, 0, 7)).") OR ({$ownership}document_type IN ('TCU','TRCPT','TALLOC','TRFND')) OR ({$ownership}document_type='TPM')");
        }
        $before = $this->evidence();
        $this->rerunMigrations();
        $this->assertSame($before, $this->evidence());
        DB::table('document_number_sequences')->insert(['location_id' => null, 'document_type' => 'TPM',
            'global_scope' => 'TPM', 'location_code' => 'PM', 'last_number' => 73]);
        $this->assertSame(73, DB::table('document_number_sequences')->value('last_number'));
    }

    public function test_conflicting_ownership_constraint_is_rejected_before_any_ddl(): void
    {
        $table = 'document_number_sequences';
        $this->replaceCheck($table, 'location_id IS NOT NULL OR global_scope IS NOT NULL');
        $before = $this->evidence();
        try {
            try {
                $this->rerunMigrations();
                $this->fail('An unrecognized ownership predicate was overwritten.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('ownership constraint', $exception->getMessage());
            }
            $this->assertSame($before, $this->evidence());
        } finally {
            $this->replaceCheck($table, $this->predicate($table, self::TYPES));
        }
    }

    public function test_invalid_rows_under_a_missing_constraint_fail_closed_without_rewriting_them(): void
    {
        $table = 'document_number_sequences';
        $this->replaceCheck($table, null);
        $id = DB::table($table)->insertGetId(['location_id' => null, 'document_type' => 'TA', 'global_scope' => null, 'location_code' => 'TEST', 'last_number' => 73]);
        $before = $this->evidence();
        try {
            try {
                $this->rerunMigrations();
                $this->fail('Invalid ownership was silently repaired.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('rows violate', $exception->getMessage());
            }
            $this->assertSame($before, $this->evidence());
        } finally {
            DB::table($table)->where('id', $id)->delete();
            app(FinancialNumberingConstraints::class)->ensure(self::TYPES);
        }
    }

    public function test_unknown_existing_financial_types_are_not_silently_removed(): void
    {
        $table = 'document_numbers';
        $this->replaceCheck($table, $this->predicate($table, [...self::TYPES, 'CUSTOM']));
        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('Unknown financial numbering constraint type');
            $this->rerunMigrations();
        } finally {
            $this->replaceCheck($table, $this->predicate($table, self::TYPES));
        }
    }
}
