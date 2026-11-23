<?php

namespace Tests\Feature;

use App\Http\Middleware\BackupWriteAdmission;
use App\Modules\AccessManagement\Models\User;
use App\Modules\BackupRestore\Jobs\VerifyIsolatedRestore;
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\LocalRecoveryReadiness;
use App\Modules\BackupRestore\Services\QueryWriteGuard;
use App\Modules\BackupRestore\Services\WriteAdmission;
use App\Modules\BackupRestore\Services\WriteCoordinationStore;
use App\Modules\BackupRestore\Services\WriteProtectionHooks;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductImageManager;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class BackupSafetyHardeningTest extends TestCase
{
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('backup_safety_probe', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->integer('amount');
        });
        DB::table('backup_safety_probe')->insert(['id' => 1, 'amount' => 0]);
        config(['backup-restore.write_admission_enabled' => true]);
        app(QueryWriteGuard::class)->install();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        config(['backup-restore.write_admission_enabled' => false]);
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        app(WriteAdmission::class)->finish();
        DB::purge('backup_write_coordination');
        DB::purge('phase6a_alias');
        Schema::dropIfExists('backup_safety_probe');
        parent::tearDown();
    }

    public function test_direct_laravel_mutations_fail_before_execution_and_reads_remain_read_only(): void
    {
        $mutations = [
            fn () => DB::table('backup_safety_probe')->increment('amount'),
            fn () => DB::statement('UPDATE backup_safety_probe SET amount=10'),
            fn () => DB::unprepared('UPDATE backup_safety_probe SET amount=20'),
            fn () => DB::delete('DELETE FROM backup_safety_probe'),
            fn () => DB::statement('/* disguised mutation */ UPDATE backup_safety_probe SET amount=30'),
            fn () => DB::statement('CALL unsupported_procedure()'),
            fn () => DB::unprepared('SELECT 1; UPDATE backup_safety_probe SET amount=40'),
            fn () => DB::select('SELECT * FROM backup_safety_probe FOR UPDATE'),
            fn () => DB::statement("SELECT amount INTO OUTFILE 'phase6a-forbidden-output' FROM backup_safety_probe"),
        ];
        foreach ($mutations as $mutation) {
            $this->failure('write_admission_required', $mutation);
        }
        $this->assertSame(0, (int) DB::table('backup_safety_probe')->value('amount'));
        $this->assertDatabaseCount('backup_write_operations', 0);
        $this->assertDatabaseCount('backup_write_barriers', 0);
    }

    public function test_secondary_connections_cannot_bypass_the_guard(): void
    {
        config(['database.connections.phase6a_alias' => config('database.connections.'.config('database.default'))]);
        $this->failure('write_admission_required', fn () => DB::connection('phase6a_alias')->table('backup_safety_probe')->increment('amount'));
        app(WriteAdmission::class)->run('service', fn () => DB::connection('phase6a_alias')->table('backup_safety_probe')->increment('amount'));
        $this->assertSame(1, (int) DB::table('backup_safety_probe')->value('amount'));
    }

    public function test_business_rollback_and_nested_admission_keep_existing_transaction_boundaries(): void
    {
        try {
            app(WriteAdmission::class)->run('service', function (): void {
                $this->assertSame(0, DB::transactionLevel());
                DB::transaction(function (): void {
                    DB::table('backup_safety_probe')->increment('amount', 100);
                    app(WriteAdmission::class)->run('service', fn () => DB::table('backup_safety_probe')->increment('amount', 50));
                    $this->assertDatabaseCount('backup_write_operations', 1);
                    throw new \RuntimeException('synthetic_business_failure');
                });
            });
            $this->fail('Expected business failure');
        } catch (\RuntimeException $failure) {
            $this->assertSame('synthetic_business_failure', $failure->getMessage());
        }
        $this->assertSame(0, (int) DB::table('backup_safety_probe')->value('amount'));
        $this->assertDatabaseCount('backup_write_operations', 0);
    }

    public function test_tampered_durable_writer_fence_cannot_authorize_sql(): void
    {
        $admission = app(WriteAdmission::class);
        $admission->begin('service');
        app(WriteCoordinationStore::class)->connection()->table('backup_write_operations')->increment('admitted_fence');
        $this->failure('write_admission_required', fn () => DB::table('backup_safety_probe')->increment('amount'));
        $this->assertSame(0, (int) DB::table('backup_safety_probe')->value('amount'));
        $this->assertDatabaseCount('backup_write_operations', 1);
        $admission->finish();
    }

    public function test_existing_writer_can_finish_while_draining_but_new_writers_are_rejected(): void
    {
        $admission = app(WriteAdmission::class);
        $admission->begin('service');
        $connection = app(WriteCoordinationStore::class)->connection();
        $connection->table('backup_write_barriers')->where('scope', 'global')->update(['state' => 'draining', 'fence' => 1]);
        DB::table('backup_safety_probe')->increment('amount');
        $this->failure('write_barrier_active', fn () => (new WriteAdmission)->begin('service'));
        $admission->finish();
        $this->assertSame(1, (int) DB::table('backup_safety_probe')->value('amount'));
        $this->assertDatabaseCount('backup_write_operations', 0);
    }

    public function test_missing_barrier_is_not_recreated_by_a_writer_assertion_or_release(): void
    {
        $admission = app(WriteAdmission::class);
        $admission->begin('service');
        $connection = app(WriteCoordinationStore::class)->connection();
        $connection->table('backup_write_barriers')->where('scope', 'global')->delete();
        $this->failure('write_coordination_unavailable', fn () => DB::table('backup_safety_probe')->increment('amount'));
        $this->failure('write_coordination_unavailable', fn () => $admission->finish());
        $this->assertDatabaseCount('backup_write_barriers', 0);
        $this->assertDatabaseCount('backup_write_operations', 1);
        $this->assertSame(0, (int) DB::table('backup_safety_probe')->value('amount'));
        /** Restore only this disposable fixture; the production recovery path cannot infer it. */
        $connection->table('backup_write_barriers')->insert(['scope' => 'global', 'state' => 'recovery_required', 'fence' => 1]);
        $admission->finish();
    }

    public function test_fixed_session_timeout_is_reapplied_after_pdo_reconnection_without_caching_fences(): void
    {
        $store = app(WriteCoordinationStore::class);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $first = $store->connection()->getPdo();
        $store->connection();
        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => $sql === 'SET SESSION innodb_lock_wait_timeout = 3'));
        DB::purge('backup_write_coordination');
        $this->assertNotSame($first, $store->connection()->getPdo());
        $this->assertCount(2, array_filter($queries, fn (string $sql): bool => $sql === 'SET SESSION innodb_lock_wait_timeout = 3'));
        $this->assertDatabaseCount('backup_write_barriers', 0);
    }

    public function test_media_service_rejects_unadmitted_work_before_touching_fake_storage(): void
    {
        Storage::fake('public');
        $this->failure('write_admission_required', fn () => app(ProductImageManager::class)->save(new User, new Product, []));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_pos_and_webhook_requests_are_retryable_only_when_admission_itself_fails(): void
    {
        app(WriteCoordinationStore::class)->locked(fn ($connection) => $connection->table('backup_write_barriers')->where('scope', 'global')->update(['state' => 'capturing']));
        foreach (['/pos/devices/1/offline-sync', '/pos/devices/1/offline-return-sync', '/webhooks/payment'] as $path) {
            $executed = false;
            $response = (new BackupWriteAdmission)->handle(Request::create($path, 'POST'), function () use (&$executed) {
                $executed = true;

                return response()->json(['acknowledged' => true]);
            });
            $this->assertSame(503, $response->getStatusCode());
            $this->assertSame('10', $response->headers->get('Retry-After'));
            $this->assertTrue(json_decode($response->getContent(), true)['retryable']);
            $this->assertFalse($executed);
        }
    }

    public function test_downstream_failure_after_a_commit_is_not_mislabeled_as_a_safe_retry(): void
    {
        $this->failure('write_coordination_unavailable', fn () => (new BackupWriteAdmission)->handle(Request::create('/webhooks/payment', 'POST'), function () {
            DB::transaction(fn () => DB::table('backup_safety_probe')->increment('amount'));
            throw new BackupFailure('write_coordination_unavailable');
        }));
        $this->assertSame(1, (int) DB::table('backup_safety_probe')->value('amount'));
        $this->assertDatabaseCount('backup_write_operations', 1);
    }

    public function test_restore_jobs_are_not_admitted_as_ordinary_business_jobs(): void
    {
        $this->failure('backup_queue_isolation_unverified', fn () => Bus::dispatchSync(new VerifyIsolatedRestore('00000000-0000-4000-8000-000000000001')));
        $this->assertDatabaseCount('backup_restore_runs', 0);
        $this->assertDatabaseCount('backup_write_operations', 0);
    }

    public function test_worker_startup_rejects_unbounded_timeouts_retry_overlap_and_unverified_drivers(): void
    {
        $definition = app(Kernel::class)->all()['queue:work']->getDefinition();
        foreach ([['database', '0'], ['database', '90'], ['redis', '60'], ['sqs', '60'], ['backup_database', '60']] as [$connection, $timeout]) {
            $input = new ArrayInput(['connection' => $connection, '--timeout' => $timeout], $definition);
            $this->failure('write_worker_unverified', fn () => app(WriteProtectionHooks::class)->starting(new CommandStarting('queue:work', $input, new NullOutput)));
        }
        $input = new ArrayInput(['connection' => 'database', '--timeout' => '60'], $definition);
        app(WriteProtectionHooks::class)->starting(new CommandStarting('queue:work', $input, new NullOutput));
        $this->assertDatabaseCount('backup_write_operations', 0);
    }

    public function test_readiness_is_sanitized_and_does_not_initialize_or_modify_database_state(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $report = app(LocalRecoveryReadiness::class)->inspect();
        $this->assertFalse($report['manual_backup_ready']);
        $this->assertFalse($report['restore_available']);
        $this->assertSame('unverified', $report['checks']['r2_live_access_and_privacy']['status']);
        $this->assertSame('blocked', $report['checks']['independent_key']['status']);
        $this->assertSame('blocked', $report['checks']['import_client']['status']);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|REPLACE)\b/i', $query);
        }
        $this->assertStringNotContainsString((string) config('app.key'), json_encode($report));
        $this->assertDatabaseCount('backup_write_barriers', 0);
        $this->assertDatabaseCount('backup_write_operations', 0);
        $this->artisan('backups:readiness')->assertFailed();
        $this->assertDatabaseCount('backup_write_barriers', 0);
    }

    public function test_unadmitted_boot_provider_and_console_writes_are_rejected_before_first_change(): void
    {
        $this->failure('write_admission_required', fn () => DB::table('backup_safety_probe')->insert(['id' => 2, 'amount' => 10]));
        $this->assertDatabaseCount('backup_safety_probe', 1);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_admission_overhead_on_synthetic_business_write_shapes(): void
    {
        $results = [];
        foreach (['sales_invoice_shape' => 12, 'treasury_shape' => 8, 'inventory_shape' => 16, 'pos_sync_shape' => 24, 'filament_shape' => 4] as $shape => $writes) {
            $times = ['unguarded_ms' => [], 'admitted_ms' => []];
            foreach (range(1, 5) as $sample) {
                foreach (['unguarded_ms', 'admitted_ms'] as $mode) {
                    config(['backup-restore.write_admission_enabled' => $mode === 'admitted_ms']);
                    $started = hrtime(true);
                    app(WriteAdmission::class)->run('service', fn () => DB::transaction(function () use ($writes): void {
                        for ($index = 0; $index < $writes; $index++) {
                            DB::table('backup_safety_probe')->increment('amount');
                        }
                    }));
                    $times[$mode][] = round((hrtime(true) - $started) / 1_000_000, 3);
                }
            }
            foreach ($times as &$samples) {
                sort($samples);
            }
            unset($samples);
            $results[$shape] = ['writes' => $writes, 'samples' => 5, 'unguarded_median_ms' => $times['unguarded_ms'][2], 'admitted_median_ms' => $times['admitted_ms'][2]];
            $this->assertGreaterThan(0, $times['admitted_ms'][2]);
        }
        config(['backup-restore.write_admission_enabled' => true]);
        $this->assertDatabaseCount('backup_write_operations', 0);
        $this->assertSame(640, (int) DB::table('backup_safety_probe')->value('amount'));
        file_put_contents(storage_path('framework/testing/phase6a-admission-benchmark.json'), json_encode(['scope' => 'synthetic write shapes, not end-to-end business latency', 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function failure(string $reason, \Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Expected '.$reason);
        } catch (BackupFailure $failure) {
            $this->assertSame($reason, $failure->getMessage());
        }
    }
}
