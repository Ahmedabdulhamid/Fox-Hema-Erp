<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\BackupRestore\Jobs\CreateFullBackup;
use App\Modules\BackupRestore\Models\BackupRun;
use App\Modules\BackupRestore\Services\BackupCoordinator;
use App\Modules\BackupRestore\Services\BackupCoverage;
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\BackupLease;
use App\Modules\BackupRestore\Services\BackupLeaseManager;
use App\Modules\BackupRestore\Services\BackupReadiness;
use App\Modules\BackupRestore\Services\CaptureWatchdog;
use App\Modules\BackupRestore\Services\CoordinatedCaptureProtection;
use App\Modules\BackupRestore\Services\WriteAdmission;
use App\Modules\BackupRestore\Services\WriteCoordinationStore;
use App\Modules\BackupRestore\Services\WriteProtectionHooks;
use App\Modules\Products\Services\ProductImageManager;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Process;
use Tests\Concerns\BackupFileEnvironment;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\Support\AdmittedQueueProbe;
use Tests\Support\VerifiedTestCaptureProtection;
use Tests\TestCase;

class BackupRecoveryHardeningTest extends TestCase
{
    use BackupFileEnvironment;
    use UsesIsolatedDatabase;

    private User $actor;

    private BackupLease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register();
        Module::query()->where('code', 'backup_restore')->update(['is_active' => true]);
        $this->actor = $this->provisionTestSuperAdmin();
        $run = BackupRun::factory()->create(['status' => 'queued', 'requested_by' => $this->actor->id]);
        $this->lease = app(BackupLeaseManager::class)->acquire($run->uuid);
        $this->setUpBackupFiles();
        config(['backup-restore.write_admission_enabled' => true]);
    }

    protected function tearDown(): void
    {
        config(['backup-restore.write_admission_enabled' => false]);
        DB::purge('backup_write_coordination');
        $this->removeBackupTestDirectory($this->backupTestRoot);
        parent::tearDown();
    }

    public function test_get_direct_database_and_file_writes_remain_admitted_through_termination(): void
    {
        $path = $this->backupTestRoot.'/public/get.txt';
        Route::get('/phase3c/get', function () use ($path) {
            $this->assertDatabaseCount('backup_write_operations', 1);
            DB::table('backup_runs')->where('uuid', $this->lease->runUuid)->update(['file_count' => 2]);
            file_put_contents($path, 'admitted');
            defer(function () use ($path): void {
                $this->assertDatabaseCount('backup_write_operations', 1);
                file_put_contents($path, '-deferred', FILE_APPEND);
            });

            return response('accepted');
        });
        $request = Request::create('/phase3c/get', 'GET');
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseCount('backup_write_operations', 1);
        $kernel->terminate($request, $response);
        $this->assertDatabaseCount('backup_write_operations', 0);
        $this->assertSame('admitted-deferred', file_get_contents($path));
        $this->assertDatabaseHas('backup_runs', ['uuid' => $this->lease->runUuid, 'file_count' => 2]);
        (new VerifiedTestCaptureProtection)->capture($this->lease, function () use ($path): void {
            $this->getJson('/phase3c/get')->assertStatus(503)->assertJson(['retryable' => true]);
            $this->assertSame('admitted-deferred', file_get_contents($path));
        });
    }

    public function test_late_application_termination_callbacks_are_inside_admission(): void
    {
        Route::get('/phase3c/terminate', function () {
            app()->terminating(function (): void {
                $this->assertDatabaseCount('backup_write_operations', 1);
            });

            return response('ok');
        });
        $this->get('/phase3c/terminate')->assertOk();
        $this->assertDatabaseCount('backup_write_operations', 0);
    }

    public function test_alternate_connection_and_backup_named_queue_do_not_bypass_admission(): void
    {
        $hooks = app(WriteProtectionHooks::class);
        $this->failure('backup_queue_isolation_unverified', fn () => $hooks->pop(fn () => $this->fail('Control route reserved'), 'backups'));
        $this->assertDatabaseCount('backup_write_operations', 0);
        (new VerifiedTestCaptureProtection)->capture($this->lease, function () use ($hooks): void {
            $this->failure('backup_queue_isolation_unverified', fn () => $hooks->pop(fn () => $this->fail('Backup-named queue escaped'), 'backups'));
        });
    }

    public function test_media_mutation_services_require_admission_even_outside_http(): void
    {
        $images = app(ProductImageManager::class);
        $this->failure('write_admission_required', fn () => $images->enqueue('public', 'products/fixture.jpg'));
        $this->failure('write_admission_required', fn () => $images->cleanup(123));
        $this->assertDatabaseCount('product_image_cleanup_tasks', 0);
        app(WriteAdmission::class)->run('service', fn () => $this->assertTrue($images->cleanup(123)));
    }

    public function test_raw_pdo_transaction_on_a_secondary_laravel_connection_retains_admission(): void
    {
        config(['database.connections.phase3c_secondary' => config('database.connections.'.config('database.default'))]);
        $connection = DB::connection('phase3c_secondary');
        $admission = app(WriteAdmission::class);
        $admission->begin('service');
        $pdo = $connection->getPdo();
        $pdo->beginTransaction();
        try {
            $this->failure('write_transactions_open', fn () => $admission->finish());
            $this->assertDatabaseCount('backup_write_operations', 1);
        } finally {
            $pdo->rollBack();
            $admission->finish();
            DB::purge('phase3c_secondary');
        }
        $this->assertDatabaseCount('backup_write_operations', 0);
    }

    public function test_unproven_dump_cleanup_keeps_the_barrier_closed(): void
    {
        $this->failure('capture_cleanup_unverified', fn () => (new VerifiedTestCaptureProtection)->capture($this->lease, function (): void {
            throw new BackupFailure('capture_cleanup_unverified');
        }));
        $this->assertDatabaseHas('backup_write_barriers', ['state' => 'capturing']);
        $this->failure('write_barrier_active', fn () => (new WriteAdmission)->begin('service'));
    }

    public function test_unsupported_worker_startup_paths_are_explicitly_blocked(): void
    {
        $definitions = app(\Illuminate\Contracts\Console\Kernel::class)->all();
        foreach ([['queue:work', 'backup_database'], ['queue:work', 'deferred'], ['queue:listen', null], ['schedule:work', null]] as [$command, $connection]) {
            $arguments = $connection === null ? [] : ['connection' => $connection];
            $input = new ArrayInput($arguments, $definitions[$command]->getDefinition());
            $this->failure('write_worker_unverified', fn () => app(WriteProtectionHooks::class)->starting(new CommandStarting($command, $input, new NullOutput)));
        }
        $input = new ArrayInput(['connection' => 'database', '--name' => 'custom'], $definitions['queue:work']->getDefinition());
        $this->failure('write_worker_unverified', fn () => app(WriteProtectionHooks::class)->starting(new CommandStarting('queue:work', $input, new NullOutput)));
        foreach (['backups', 'default,backup-restores'] as $queues) {
            $input = new ArrayInput(['connection' => 'database', '--queue' => $queues], $definitions['queue:work']->getDefinition());
            $this->failure('write_worker_unverified', fn () => app(WriteProtectionHooks::class)->starting(new CommandStarting('queue:work', $input, new NullOutput)));
        }
        $this->failure('backup_queue_isolation_unverified', fn () => Bus::dispatchSync(new CreateFullBackup($this->lease->runUuid)));
    }

    public function test_real_alternate_database_worker_cannot_reserve_a_backup_named_job_during_capture(): void
    {
        if (! Schema::hasTable('backup_write_test_evidence')) {
            Schema::create('backup_write_test_evidence', function (Blueprint $table): void {
                $table->integer('id')->primary();
                $table->decimal('balance', 18, 4);
                $table->integer('quantity');
            });
        }
        DB::table('backup_write_test_evidence')->insert(['id' => 1, 'balance' => '123.4567', 'quantity' => 9]);
        AdmittedQueueProbe::dispatch()->onConnection('database')->onQueue('backups');
        $worker = fn (): Process => new Process([PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=backups', '--once', '--sleep=0', '--no-interaction'], base_path(), ['BACKUP_WRITE_ADMISSION_ENABLED' => 'true']);
        config(['backup-restore.capture_timeout' => 45]);
        $duringCapture = (new VerifiedTestCaptureProtection)->capture($this->lease, function () use ($worker): Process {
            $process = $worker();
            $process->setTimeout(30)->run();

            return $process;
        });
        /** Laravel suppresses console lifecycle events in testing; the worker catches pre-pop refusal. */
        $this->assertTrue($duringCapture->isSuccessful(), $duringCapture->getOutput().$duringCapture->getErrorOutput());
        $this->assertDatabaseHas('jobs', ['queue' => 'backups', 'attempts' => 0, 'reserved_at' => null]);
        $this->assertDatabaseHas('backup_write_test_evidence', ['id' => 1, 'quantity' => 9]);
        $this->assertDatabaseCount('backup_write_operations', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $process = $worker();
        $process->setTimeout(30)->run();
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $this->assertDatabaseHas('jobs', ['queue' => 'backups', 'attempts' => 0, 'reserved_at' => null]);
        $this->assertDatabaseHas('backup_write_test_evidence', ['id' => 1, 'quantity' => 9]);
        $this->assertDatabaseCount('backup_write_operations', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_readiness_separates_code_runtime_supervision_and_missing_tables(): void
    {
        $coverage = app(BackupCoverage::class)->report();
        $this->assertSame('partial', $coverage['code_coverage']['status']);
        $this->assertSame('unverified', $coverage['runtime_coverage']['status']);
        config(['backup-restore.write_admission_enabled' => false]);
        Schema::rename('backup_write_barriers', 'phase3c_hidden_barriers');
        try {
            $blockers = app(BackupReadiness::class)->blockers();
            foreach (['write_coordination_tables_missing', 'runtime_coverage_unverified', 'worker_supervision_unverified', 'watchdog_supervision_unverified',
                'external_writers_unverified', 'r2_not_configured', 'dump_binary_unavailable'] as $code) {
                $this->assertContains($code, $blockers);
            }
            $this->assertSame('missing', app(BackupCoverage::class)->report()['coordination_tables']['status']);
        } finally {
            Schema::rename('phase3c_hidden_barriers', 'backup_write_barriers');
        }
    }

    public function test_stop_proof_runs_without_coordination_locks_and_rechecks_successor_before_reopening(): void
    {
        $this->crash();
        app()->instance(CaptureWatchdog::class, new class extends CaptureWatchdog
        {
            public function captureStopped(object $barrier): bool
            {
                if (app(WriteCoordinationStore::class)->connection()->transactionLevel() !== 0) {
                    throw new \LogicException('Recovery held locks while stopping processes');
                }
                DB::table('backup_write_barriers')->update(['fence' => 99]);

                return true;
            }
        });
        $this->failure('write_barrier_fenced', fn () => app(CoordinatedCaptureProtection::class)->recover($this->actor));
        $this->assertDatabaseHas('backup_write_barriers', ['state' => 'capturing', 'fence' => 99]);
    }

    public function test_independent_watchdog_restart_recovers_once_and_fences_the_old_lease(): void
    {
        $this->crash();
        app()->instance(CaptureWatchdog::class, new class extends CaptureWatchdog
        {
            public function captureStopped(object $barrier): bool
            {
                return true;
            }
        });
        $this->artisan('backups:watchdog', ['actor' => $this->actor->uuid, '--once' => true])->assertSuccessful();
        $this->assertDatabaseHas('backup_write_barriers', ['state' => 'open', 'fence' => 8]);
        $this->failure('lease_lost', fn () => app(BackupLeaseManager::class)->heartbeat($this->lease));
        $this->artisan('backups:watchdog', ['actor' => $this->actor->uuid, '--once' => true])->assertSuccessful();
        $this->assertDatabaseHas('backup_write_barriers', ['state' => 'open', 'fence' => 8]);
    }

    public function test_watchdog_authorization_and_missing_process_proof_fail_closed(): void
    {
        $this->crash();
        $unauthorized = User::factory()->create();
        $this->artisan('backups:watchdog', ['actor' => $unauthorized->uuid, '--once' => true])->assertFailed();
        $this->artisan('backups:watchdog', ['actor' => $this->actor->uuid, '--once' => true])->assertFailed();
        $this->assertDatabaseHas('backup_write_barriers', ['state' => 'capturing', 'fence' => 7]);
    }

    public function test_disabled_admission_does_not_bypass_a_durable_capture_during_recovery(): void
    {
        $this->crash();
        config(['backup-restore.write_admission_enabled' => false]);
        $this->failure('write_barrier_recovery_required', fn () => app(BackupCoordinator::class)->recover($this->actor));
        $this->assertDatabaseHas('backup_write_barriers', ['state' => 'capturing', 'fence' => 7]);
        $this->assertSame('recovery_required', app(WriteCoordinationStore::class)->status(true)['state']);
    }

    private function crash(): void
    {
        app(WriteCoordinationStore::class)->locked(function (Connection $connection): void {
            $connection->table('backup_write_barriers')->update(['state' => 'capturing', 'fence' => 7,
                'run_uuid' => $this->lease->runUuid, 'lease_fence' => $this->lease->fence,
                'owner_hash' => hash('sha256', $this->lease->owner), 'deadline_at' => now('UTC')->subMinute()]);
            $connection->table('backup_operation_leases')->update(['expires_at' => now()->subMinute()]);
        });
    }

    private function failure(string $code, \Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Expected '.$code);
        } catch (BackupFailure $failure) {
            $this->assertSame($code, $failure->getMessage());
        }
    }
}
