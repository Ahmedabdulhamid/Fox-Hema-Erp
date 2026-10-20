<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\DocumentNumber;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Concerns\DocumentTestSetup;
use Tests\TestCase;

class DocumentNumberingTest extends TestCase
{
    use DocumentTestSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
    }

    private function allocate(Location $location, string $type = 'SO', ?string $uuid = null): string
    {
        return DB::transaction(fn () => app(DocumentNumberService::class)->allocate($location->id, $type, $uuid ?? (string) Str::uuid7()), 3);
    }

    public function test_locations_types_and_warehouse_have_independent_continuous_counters(): void
    {
        $other = Location::create(['name' => 'Second branch', 'type' => 'branch', 'is_active' => true]);
        $warehouse = Location::create(['name' => 'Central', 'type' => 'warehouse', 'is_active' => true]);
        foreach ([$this->documentBranch, $other, $warehouse] as $location) {
            foreach (['SO', 'PO', 'GRN', 'TRF'] as $type) {
                $this->assertSame($location->code.'-'.$type.'-'.now()->format('Y').'-000001', $this->allocate($location, $type));
            }
        }
        $this->assertStringEndsWith('-000002', $this->allocate($this->documentBranch));
        $this->assertSame(13, DocumentNumber::count());
    }

    public function test_replay_and_rollback_are_atomic_and_identity_cannot_move_locations(): void
    {
        $uuid = (string) Str::uuid7();
        $first = $this->allocate($this->documentBranch, uuid: $uuid);
        $this->assertSame($first, $this->allocate($this->documentBranch, uuid: strtoupper($uuid)));
        $this->assertSame(1, DocumentNumber::count());
        try {
            DB::transaction(function (): void {
                $this->allocate($this->documentBranch);
                throw new \RuntimeException('Rollback owner transaction');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Rollback owner transaction', $exception->getMessage());
        }
        $this->assertSame(1, DocumentNumber::count());
        $this->assertStringEndsWith('-000002', $this->allocate($this->documentBranch));
        $other = Location::create(['name' => 'Other owner', 'type' => 'branch', 'is_active' => true]);
        $this->expectException(ValidationException::class);
        $this->allocate($other, uuid: $uuid);
    }

    public function test_calendar_year_changes_do_not_reset_counters_and_name_changes_do_not_change_prefix(): void
    {
        $this->travelTo(now()->setDate(2026, 12, 31));
        $this->assertStringEndsWith('-2026-000001', $this->allocate($this->documentBranch));
        $code = $this->documentBranch->code;
        $this->documentBranch->update(['name' => 'Renamed branch']);
        $this->travelTo(now()->setDate(2027, 1, 1));
        $this->assertSame($code.'-SO-2027-000002', $this->allocate($this->documentBranch));
        $this->travelBack();
    }

    public function test_legacy_collisions_are_skipped_without_max_or_rewriting_history(): void
    {
        $legacy = SalesOrder::factory()->create(['location_id' => $this->documentBranch->id,
            'order_number' => $this->documentBranch->code.'-SO-'.now()->format('Y').'-000001']);
        $original = $legacy->refresh()->getAttributes();
        $this->assertStringEndsWith('-000002', $this->allocate($this->documentBranch));
        $this->assertSame($original, $legacy->fresh()->getAttributes());
        $this->expectException(ValidationException::class);
        $this->allocate($this->documentBranch, uuid: $legacy->uuid);
    }

    public function test_number_ledger_is_immutable_through_normal_quiet_and_bulk_application_writes(): void
    {
        $this->allocate($this->documentBranch);
        $row = DocumentNumber::firstOrFail();
        foreach ([fn () => $row->forceFill(['number' => 'changed'])->save(), fn () => $row->saveQuietly(), fn () => $row->deleteQuietly(),
            fn () => DocumentNumber::whereKey($row->id)->update(['number' => 'changed']), fn () => DocumentNumber::whereKey($row->id)->delete(),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Allocated number mutation was accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, DocumentNumber::count());
    }

    public function test_database_constraints_prevent_duplicate_number_and_document_identity(): void
    {
        $this->allocate($this->documentBranch);
        $values = DocumentNumber::firstOrFail()->getAttributes();
        unset($values['id']);
        $values['document_uuid'] = (string) Str::uuid7();
        $this->expectException(QueryException::class);
        DB::table('document_numbers')->insert($values);
    }

    public function test_number_allocation_requires_a_document_transaction(): void
    {
        $this->expectException(\LogicException::class);
        app(DocumentNumberService::class)->allocate($this->documentBranch->id, 'SO', (string) Str::uuid7());
    }

    public function test_concurrent_independent_mariadb_connections_never_duplicate_or_reallocate_numbers(): void
    {
        $configuration = config('database.connections.mysql');
        $this->assertSame('mysql', DB::getDriverName());
        $same = (string) Str::uuid7();
        $identities = [$same, $same, (string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7()];
        $workers = [];
        $start = microtime(true) + 2;
        try {
            foreach ($identities as $identity) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/DocumentNumberWorker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::connection()->getDatabaseName(),
                    'DB_HOST' => $configuration['host'], 'DB_PORT' => (string) $configuration['port'],
                    'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => $configuration['password'], 'DB_URL' => '',
                    'APP_CONFIG_CACHE' => base_path('storage/framework/phase3-no-config-cache.php'),
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'DOCUMENT_LOCATION' => (string) $this->documentBranch->id, 'DOCUMENT_UUID' => $identity, 'DOCUMENT_START' => (string) $start,
                ]);
                $process->setTimeout(90)->start();
                $workers[] = $process;
            }
            $numbers = [];
            foreach ($workers as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $numbers[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['number'];
            }
            $this->assertSame($numbers[0], $numbers[1]);
            $this->assertCount(5, array_unique($numbers));
            $this->assertSame(5, DocumentNumber::count());
            $this->assertSame(5, (int) DB::table('document_number_sequences')->value('last_number'));
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }
}
