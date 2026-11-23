<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerSettlementSchemaRepairTest extends TestCase
{
    public function test_forward_repair_handles_missing_schema_partial_columns_and_preserves_financial_evidence(): void
    {
        $database = DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || DB::getDriverName() !== 'mysql'
            || ! preg_match('/^codex_public_ids_test_treasury_[a-zA-Z0-9_]+$/D', $database)
            || DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
            throw new \LogicException('Repair tests require a disposable schema.');
        }
        $runner = app('migrator');
        $repository = $runner->getRepository();
        if (! $repository->repositoryExists()) {
            $repository->createRepository();
        }
        $paths = [...$runner->paths(), database_path('migrations'), base_path('tests/Fixtures/Migrations')];
        $files = array_filter($runner->getMigrationFiles($paths), fn (string $file): bool => ! str_contains($file, '2026_10_26_021609_add_customer_settlement_to_payments')
            && ! str_contains($file, '2026_10_26_021610_add_customer_settlement_treasury_links')
            && ! str_contains($file, 'repair_missing_phase_seven_customer_settlement_schema'));
        $runner->run(array_values($files));
        $this->assertFalse(Schema::hasTable('payment_customers'));
        Schema::table('payments', fn ($table) => $table->foreignId('customer_id')->nullable());
        $payment = Payment::factory()->create();
        $invoice = SalesInvoice::factory()->create();
        DB::table('payment_allocations')->insert(['uuid' => (string) Str::uuid7(), 'payment_id' => $payment->id,
            'sales_invoice_id' => $invoice->id, 'amount' => '10.0000', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('document_number_sequences')->insert(['location_id' => null, 'document_type' => 'TA', 'global_scope' => 'TA', 'location_code' => 'TR', 'last_number' => 73]);
        $before = [];
        foreach (['payments', 'payment_allocations', 'payment_refunds', 'document_number_sequences', 'document_numbers', 'migrations'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }
        $path = app_path('Modules/Treasury/Database/Migrations/2026_10_26_030528_repair_missing_phase_seven_customer_settlement_schema.php');
        $runner->run([$path]);
        foreach ($before as $table => $rows) {
            if ($table === 'migrations') {
                $this->assertSame($rows, DB::table($table)->whereIn('id', array_column($rows, 'id'))->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all());

                continue;
            }
            $after = DB::table($table)->orderBy('id')->get();
            $this->assertCount(count($rows), $after);
            foreach ($rows as $index => $row) {
                $this->assertSame($row, array_intersect_key((array) $after[$index], $row));
            }
        }
        $this->assertTrue(Schema::hasTable('payment_customers'));
        $this->assertTrue(Schema::hasTable('customer_invoice_accounts'));
        $this->assertTrue(Schema::hasColumns('payments', ['customer_id', 'payment_date', 'treasury_account_id']));
        $this->assertTrue(Schema::hasColumns('payment_allocations', ['code', 'operation_key', 'release_key', 'released_at']));
        $this->assertTrue(Schema::hasColumns('payment_refunds', ['recovered_at', 'treasury_operation_id', 'recovery_operation_id']));
        foreach ([
            ['payment_customers', ['uuid']], ['payment_customers', ['code']],
            ['customer_invoice_accounts', ['sales_invoice_id']], ['customer_invoice_accounts', ['operation_key']],
            ['payments', ['payment_number']], ['payments', ['idempotency_key']],
            ['payment_allocations', ['operation_key']], ['payment_allocations', ['release_key']],
            ['payment_refunds', ['refund_number']], ['payment_refunds', ['recovery_key']],
            ['payment_refunds', ['treasury_operation_id']], ['payment_refunds', ['recovery_operation_id']],
        ] as [$table,$columns]) {
            $this->assertTrue(Schema::hasIndex($table, $columns, 'unique'));
        }
        $this->assertFalse(Schema::hasIndex('payment_allocations', 'uq_payment_invoice_allocation'));
        $this->assertTrue(Schema::hasIndex('payment_allocations', 'payment_allocations_payment_id_sales_invoice_id_index'));
        foreach ([
            ['payments', 'customer_id', 'payment_customers'], ['payments', 'treasury_account_id', 'treasury_accounts'],
            ['payment_allocations', 'allocated_by', 'users'], ['payment_allocations', 'released_by', 'users'],
            ['payment_refunds', 'recovered_by', 'users'], ['payment_refunds', 'treasury_operation_id', 'treasury_operations'],
            ['payment_refunds', 'recovery_operation_id', 'treasury_operations'],
        ] as [$table,$column,$target]) {
            $this->assertTrue(collect(Schema::getForeignKeys($table))->contains(fn (array $key): bool => $key['columns'] === [$column] && $key['foreign_table'] === $target && $key['on_delete'] === 'restrict'));
        }
        $snapshot = DB::table('document_number_sequences')->orderBy('id')->get()->toJson();
        $migration = require $path;
        $migration->up();
        $this->assertSame($snapshot, DB::table('document_number_sequences')->orderBy('id')->get()->toJson());
        $this->assertSame(73, (int) DB::table('document_number_sequences')->where('global_scope', 'TA')->value('last_number'));
        foreach (['CUS', 'RCPT', 'ALLOC', 'RFND'] as $prefix) {
            $number = DB::transaction(fn (): string => app(DocumentNumberService::class)->allocateFinancial($prefix, (string) Str::uuid7()));
            $this->assertSame($prefix.'-000001', $number);
        }
    }
}
