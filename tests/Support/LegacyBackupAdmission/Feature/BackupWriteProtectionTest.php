<?php

namespace Tests\Feature;

use App\Http\Middleware\BackupWriteAdmission;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\BackupRestore\Models\BackupRun;
use App\Modules\BackupRestore\Services\BackupCoordinator;
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\BackupLease;
use App\Modules\BackupRestore\Services\BackupLeaseManager;
use App\Modules\BackupRestore\Services\CaptureProtection;
use App\Modules\BackupRestore\Services\CaptureWatchdog;
use App\Modules\BackupRestore\Services\CoordinatedCaptureProtection;
use App\Modules\BackupRestore\Services\WriteAdmission;
use App\Modules\BackupRestore\Services\WriteCoordinationStore;
use App\Modules\BackupRestore\Services\WriteProtectionHooks;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\Support\AdmittedQueueProbe;
use Tests\Support\VerifiedTestCaptureProtection;
use Tests\TestCase;

class BackupWriteProtectionTest extends TestCase
{
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
        if (! Schema::hasTable('backup_write_test_evidence')) {
            Schema::create('backup_write_test_evidence', function (Blueprint $table): void {
                $table->unsignedInteger('id')->primary();
                $table->decimal('balance', 18, 4);
                $table->integer('quantity');
            });
        }
        DB::table('backup_write_test_evidence')->insert(['id' => 1, 'balance' => '123.4567', 'quantity' => 9]);
        config(['backup-restore.write_admission_enabled' => true, 'backup-restore.drain_timeout' => 2, 'backup-restore.capture_timeout' => 5]);
        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        config(['backup-restore.write_admission_enabled' => false]);
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::purge('backup_write_coordination');
        parent::tearDown();
    }

    public function test_production_protection_stays_blocked_despite_configuration_flags(): void
    {
        config(['backup-restore.capture_protection_verified' => true, 'backup-restore.watchdog_verified' => true]);
        $protection = app(CaptureProtection::class);
        $this->assertInstanceOf(CoordinatedCaptureProtection::class, $protection);
        $this->assertContains('write_paths_unverified', $protection->blockers());
        $this->assertContains('external_writers_unverified', $protection->blockers());
        $this->assertContains('capture_watchdog_unavailable', $protection->blockers());
        $this->failure('write_paths_unverified', fn () => $protection->capture($this->lease, fn () => $this->fail('Capture ran')));
        $this->assertDatabaseCount('backup_write_barriers', 0);
    }

    public function test_migration_keys_and_read_only_status_without_initialization(): void
    {
        $this->assertSame('uninitialized', app(WriteCoordinationStore::class)->status()['state']);
        $this->assertDatabaseCount('backup_write_barriers', 0);
        $indexes = Schema::getIndexes('backup_write_operations');
        $this->assertContains(['uuid'], array_column($indexes, 'columns'));
        $this->assertContains(['started_at'], array_column($indexes, 'columns'));
        $this->assertContains(['deadline_at'], array_column(Schema::getIndexes('backup_write_barriers'), 'columns'));
    }

    public function test_nested_admission_and_nested_transactions_remain_tracked_until_commit(): void
    {
        $admission = app(WriteAdmission::class);
        $admission->begin('http');
        DB::beginTransaction();
        DB::beginTransaction();
        DB::table('backup_write_test_evidence')->where('id', 1)->update(['balance' => '124.4567']);
        $admission->begin('service');
        $this->assertDatabaseCount('backup_write_operations', 1);
        DB::commit();
        $admission->finish();
        $this->failure('write_transactions_open', fn () => $admission->finish());
        $this->assertDatabaseCount('backup_write_operations', 1);
        DB::commit();
        $admission->finish();
        $this->assertDatabaseCount('backup_write_operations', 0);
        $this->assertDatabaseHas('backup_write_test_evidence', ['balance' => '124.4567', 'quantity' => 9]);
    }

    public function test_rollback_and_failed_operation_release_after_transactions_are_closed(): void
    {
        try {
            app(WriteAdmission::class)->run('http', fn () => DB::transaction(function (): void {
                DB::table('backup_write_test_evidence')->where('id', 1)->update(['quantity' => 0]);
                throw new \RuntimeException('synthetic operation failure');
            }));
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('backup_write_operations', 0);
            $this->assertDatabaseHas('backup_write_test_evidence', ['balance' => '123.4567', 'quantity' => 9]);
        }
    }

    public function test_successful_capture_closes_admission_only_for_capture_callback(): void
    {
        $guard = new VerifiedTestCaptureProtection;
        $result = $guard->capture($this->lease, function () use ($guard): string {
            $guard->assertHeld($this->lease);
            $this->assertSame('capturing', app(WriteCoordinationStore::class)->status()['state']);
            $this->assertSame('globally_quiesced', $guard->mode());
            $this->failure('write_barrier_active', fn () => (new WriteAdmission)->begin('queue'));
            $this->assertDatabaseCount('backup_write_operations', 0);

            return 'consistent fixture';
        });
        $this->assertSame('consistent fixture', $result);
        app(WriteAdmission::class)->run('service', fn () => $this->assertSame('open', app(WriteCoordinationStore::class)->status()['state']));
        $this->assertDatabaseHas('backup_write_test_evidence', ['balance' => '123.4567', 'quantity' => 9]);
    }

    public function test_drain_timeout_never_expires_or_terminates_a_financial_operation(): void
    {
        $admission = app(WriteAdmission::class);
        $admission->begin('service');
        DB::beginTransaction();
        DB::table('backup_write_test_evidence')->where('id', 1)->increment('quantity');
        $started = hrtime(true);
        $this->failure('write_drain_timeout', fn () => (new VerifiedTestCaptureProtection)->capture($this->lease, fn () => $this->fail('Undrained capture')));
        $this->assertLessThan(5, (hrtime(true) - $started) / 1e9);
        $this->assertSame(1, DB::transactionLevel());
        $this->assertDatabaseCount('backup_write_operations', 1);
        $this->assertSame('open', app(WriteCoordinationStore::class)->status()['state']);
        DB::commit();
        $admission->finish();
        $this->assertDatabaseHas('backup_write_test_evidence', ['balance' => '123.4567', 'quantity' => 10]);
    }

    public function test_failed_capture_and_expired_deadline_release_after_callback_stops(): void
    {
        $guard = new VerifiedTestCaptureProtection;
        $this->failure('files_changed', fn () => $guard->capture($this->lease, function (): void {
            throw new BackupFailure('files_changed');
        }));
        $this->assertSame('open', app(WriteCoordinationStore::class)->status()['state']);
        $this->failure('write_capture_expired', fn () => $guard->capture($this->lease, function () use ($guard): void {
            DB::table('backup_write_barriers')->update(['deadline_at' => now('UTC')->subMinute()]);
            $guard->assertHeld($this->lease);
        }));
        $this->assertSame('open', app(WriteCoordinationStore::class)->status()['state']);
        $this->assertSame('write_capture_expired', app(WriteCoordinationStore::class)->status()['failure']);
    }

    public function test_wrong_lease_cannot_close_or_assert_capture(): void
    {
        $wrong = new BackupLease($this->lease->runUuid, bin2hex(random_bytes(32)), $this->lease->fence);
        $guard = new VerifiedTestCaptureProtection;
        $this->failure('write_barrier_fenced', fn () => $guard->capture($wrong, fn () => $this->fail('Forged owner ran')));
        $this->assertSame('uninitialized', app(WriteCoordinationStore::class)->status()['state']);
        $guard->capture($this->lease, function () use ($guard, $wrong): void {
            $this->failure('write_barrier_fenced', fn () => $guard->assertHeld($wrong));
        });
    }

    public function test_stale_capture_cannot_release_a_successor_barrier(): void
    {
        $guard = new VerifiedTestCaptureProtection;
        $this->failure('write_barrier_fenced', fn () => $guard->capture($this->lease, function (): void {
            DB::table('backup_write_barriers')->update(['fence' => 200, 'owner_hash' => hash('sha256', 'synthetic-successor')]);
        }));
        $this->assertDatabaseHas('backup_write_barriers', ['state' => 'capturing', 'fence' => 200]);
        $this->failure('write_barrier_active', fn () => (new WriteAdmission)->begin('http'));
    }

    public function test_watchdog_stop_claim_cannot_recover_an_unexpired_capture(): void
    {
        app()->instance(CaptureWatchdog::class, new class extends CaptureWatchdog
        {
            public function captureStopped(object $barrier): bool
            {
                return true;
            }
        });
        $guard = new VerifiedTestCaptureProtection;
        $guard->capture($this->lease, function () use ($guard): void {
            $this->failure('write_barrier_recovery_required', fn () => (new CoordinatedCaptureProtection)->recover($this->actor));
            $guard->assertHeld($this->lease);
        });
        $this->assertSame('open', app(WriteCoordinationStore::class)->status()['state']);
    }

    public function test_pos_sale_and_return_sync_are_retryable_without_controller_acknowledgement(): void
    {
        (new VerifiedTestCaptureProtection)->capture($this->lease, function (): void {
            foreach (['offline-sync', 'offline-return-sync'] as $endpoint) {
                $this->postJson('/pos/devices/1/'.$endpoint, ['client_transaction_id' => 'unchanged-replay-key'])
                    ->assertStatus(503)->assertHeader('Retry-After', '10')->assertJson(['retryable' => true, 'error' => 'backup_capture_busy'])
                    ->assertJsonMissingPath('acknowledged')->assertJsonMissingPath('transaction');
            }
        });
        $this->assertDatabaseCount('pos_transactions', 0);
        $this->assertDatabaseHas('backup_write_test_evidence', ['balance' => '123.4567', 'quantity' => 9]);
    }

    public function test_get_and_media_post_are_blocked_before_the_handler(): void
    {
        $mutated = false;
        (new VerifiedTestCaptureProtection)->capture($this->lease, function () use (&$mutated): void {
            $middleware = new BackupWriteAdmission;
            $response = $middleware->handle(Request::create('/read-only', 'GET'), fn () => response('readable'));
            $this->assertSame(503, $response->getStatusCode());
            $response = $middleware->handle(Request::create('/livewire/upload-file', 'POST'), function () use (&$mutated) {
                $mutated = true;

                return response('uploaded');
            });
            $this->assertSame(503, $response->getStatusCode());
        });
        $this->assertFalse($mutated);
    }

    public function test_queue_reservation_and_acknowledgement_are_inside_one_admission(): void
    {
        $job = Mockery::mock(Job::class);
        $hooks = app(WriteProtectionHooks::class);
        $this->assertSame($job, $hooks->pop(function () use ($job): Job {
            $this->assertDatabaseCount('backup_write_operations', 1);

            return $job;
        }, 'default'));
        $this->assertDatabaseCount('backup_write_operations', 1);
        $this->failure('write_drain_timeout', fn () => (new VerifiedTestCaptureProtection)->capture($this->lease, fn () => $this->fail('Queue was not drained')));
        $hooks->attempted(new JobAttempted('database', $job));
        $this->assertDatabaseCount('backup_write_operations', 0);
        (new VerifiedTestCaptureProtection)->capture($this->lease, function () use ($hooks): void {
            $this->assertNull($hooks->pop(fn () => $this->fail('Blocked queue was popped'), 'default'));
        });
    }

    public function test_sync_job_body_and_cli_writer_have_nested_admission(): void
    {
        $hooks = app(WriteProtectionHooks::class);
        $input = new ArrayInput([]);
        $hooks->starting(new CommandStarting('reporting:stock_transfer-backfill', $input, new NullOutput));
        $this->assertDatabaseCount('backup_write_operations', 1);
        Bus::dispatchSync(new class
        {
            public function handle(): void
            {
                if (DB::table('backup_write_operations')->count() !== 1) {
                    throw new \LogicException('Nested job escaped admission.');
                }
                DB::table('backup_write_test_evidence')->where('id', 1)->increment('quantity');
            }
        });
        $this->assertDatabaseCount('backup_write_operations', 1);
        $hooks->finished(new CommandFinished('reporting:stock_transfer-backfill', $input, new NullOutput, 0));
        $this->assertDatabaseCount('backup_write_operations', 0);
        $this->assertDatabaseHas('backup_write_test_evidence', ['quantity' => 10]);
    }

    public function test_cli_writers_are_rejected_before_execution_during_capture(): void
    {
        (new VerifiedTestCaptureProtection)->capture($this->lease, function (): void {
            $this->failure('write_barrier_active', fn () => app(WriteProtectionHooks::class)->starting(
                new CommandStarting('products:cleanup-images', new ArrayInput([]), new NullOutput)));
        });
    }

    public function test_coordination_failure_is_sanitized_and_pos_fails_closed(): void
    {
        $fake = Mockery::mock(WriteCoordinationStore::class)->makePartial();
        $fake->shouldReceive('connection')->andThrow(new \RuntimeException('fixture-secret connection details'));
        app()->instance(WriteCoordinationStore::class, $fake);
        $this->failure('write_coordination_unavailable', fn () => app(WriteAdmission::class)->begin('http'));
        $this->postJson('/pos/devices/1/offline-sync')->assertStatus(503)->assertJson(['retryable' => true]);
        $this->assertDatabaseCount('pos_transactions', 0);
    }

    public function test_unauthorized_actor_cannot_recover_the_barrier(): void
    {
        $user = User::factory()->create();
        $this->expectException(HttpException::class);
        (new CoordinatedCaptureProtection)->recover($user);
    }

    public function test_expired_barrier_stays_closed_without_independent_stop_proof(): void
    {
        $this->crashedBarrier();
        $this->failure('write_barrier_active', fn () => (new WriteAdmission)->begin('http'));
        $this->failure('write_barrier_recovery_required', fn () => (new CoordinatedCaptureProtection)->recover($this->actor));
        $this->failure('write_barrier_recovery_required', fn () => app(BackupCoordinator::class)->recover($this->actor));
        $this->assertSame('recovery_required', app(WriteCoordinationStore::class)->status()['state']);
        $this->assertDatabaseHas('backup_runs', ['uuid' => $this->lease->runUuid, 'status' => 'capturing']);
    }

    public function test_watchdog_proof_recovers_barrier_with_new_fence_but_never_expires_operations(): void
    {
        app(WriteAdmission::class)->begin('service');
        $this->crashedBarrier();
        app()->instance(CaptureWatchdog::class, new class extends CaptureWatchdog
        {
            public function captureStopped(object $barrier): bool
            {
                return $barrier->fence === 10;
            }
        });
        $this->assertTrue((new CoordinatedCaptureProtection)->recover($this->actor));
        $this->assertDatabaseHas('backup_write_barriers', ['state' => 'open', 'fence' => 11]);
        $this->assertDatabaseHas('backup_runs', ['uuid' => $this->lease->runUuid, 'status' => 'failed', 'failure_code' => 'worker_interrupted']);
        $this->assertDatabaseCount('backup_write_operations', 1);
        app(WriteAdmission::class)->finish();
    }

    public function test_concurrent_http_and_queue_transactions_drain_without_an_admission_race(): void
    {
        config(['backup-restore.drain_timeout' => 10]);
        DB::table('backup_write_test_evidence')->insert(['id' => 2, 'balance' => '20.0000', 'quantity' => 5]);
        $code = $this->childBootstrap().<<<'PHP'
        $admission = app(App\Modules\BackupRestore\Services\WriteAdmission::class);
        $admission->begin($argv[1]);
        Illuminate\Support\Facades\DB::beginTransaction();
        Illuminate\Support\Facades\DB::table('backup_write_test_evidence')->where('id', (int)$argv[2])->increment('quantity');
        echo "READY\n"; fflush(STDOUT);
        $deadline = microtime(true) + 15;
        do {
            $state = app(App\Modules\BackupRestore\Services\WriteCoordinationStore::class)->status()['state'];
            if ($state === 'draining') { break; }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if ($state !== 'draining') { throw new RuntimeException('No drain signal'); }
        Illuminate\Support\Facades\DB::commit();
        $admission->finish();
        try {
            $admission->begin($argv[1]);
            throw new RuntimeException('Admission slipped through closed barrier');
        } catch (App\Modules\BackupRestore\Services\BackupFailure $failure) {
            echo $failure->getMessage();
        }
        PHP;
        $children = [new Process([PHP_BINARY, '-r', $code, 'http', '1'], base_path()), new Process([PHP_BINARY, '-r', $code, 'queue', '2'], base_path())];
        foreach ($children as $child) {
            $child->setTimeout(25)->start();
        }
        try {
            $this->waitUntil(fn (): bool => DB::table('backup_write_operations')->count() === 2
                && str_contains($children[0]->getOutput(), 'READY') && str_contains($children[1]->getOutput(), 'READY'));
            (new VerifiedTestCaptureProtection)->capture($this->lease, function () use ($children): void {
                foreach ($children as $child) {
                    $child->wait();
                    $this->assertTrue($child->isSuccessful(), $child->getErrorOutput());
                    $this->assertStringContainsString('write_barrier_active', $child->getOutput());
                }
                $this->assertDatabaseCount('backup_write_operations', 0);
                $this->assertDatabaseHas('backup_write_test_evidence', ['id' => 1, 'balance' => '123.4567', 'quantity' => 10]);
                $this->assertDatabaseHas('backup_write_test_evidence', ['id' => 2, 'quantity' => 6]);
            });
        } catch (BackupFailure $failure) {
            $this->fail($failure->getMessage().' '.json_encode(array_map(fn (Process $child): array => [
                'output' => $child->getOutput(), 'error' => $child->getErrorOutput(), 'running' => $child->isRunning(),
            ], $children)));
        } finally {
            foreach ($children as $child) {
                if ($child->isRunning()) {
                    $child->stop(1);
                }
            }
        }
    }

    public function test_duplicate_backup_delivery_and_stale_lease_cannot_acquire_or_publish(): void
    {
        $leases = app(BackupLeaseManager::class);
        $this->assertNull($leases->acquire($this->lease->runUuid));
        $this->assertNull($leases->acquire($this->lease->runUuid));
        $wrong = new BackupLease($this->lease->runUuid, bin2hex(random_bytes(32)), $this->lease->fence);
        $this->failure('lease_lost', fn () => $leases->transition($wrong, 'capturing', 'encrypting'));
        $this->assertFalse($leases->fail($wrong, new BackupFailure('worker_interrupted')));
        $this->assertSame('capturing', BackupRun::query()->where('uuid', $this->lease->runUuid)->firstOrFail()->status);
        app(WriteCoordinationStore::class)->connection()->table('backup_operation_leases')->where('scope', 'global')->update(['expires_at' => now()->subMinute()]);
        $this->failure('lease_lost', fn () => $leases->transition($this->lease, 'capturing', 'encrypting'));
        $this->assertSame('capturing', BackupRun::query()->where('uuid', $this->lease->runUuid)->firstOrFail()->status);
    }

    public function test_killed_writer_keeps_durable_admission_and_rolls_back_financial_work(): void
    {
        $code = $this->childBootstrap().<<<'PHP'
        app(App\Modules\BackupRestore\Services\WriteAdmission::class)->begin('queue');
        Illuminate\Support\Facades\DB::beginTransaction();
        Illuminate\Support\Facades\DB::table('backup_write_test_evidence')->where('id',1)->update(['balance'=>'0.0000','quantity'=>0]);
        echo "READY\n"; fflush(STDOUT);
        sleep(20);
        PHP;
        $child = new Process([PHP_BINARY, '-r', $code], base_path());
        $child->setTimeout(30)->start();
        try {
            $this->waitUntil(fn (): bool => str_contains($child->getOutput(), 'READY'));
        } finally {
            $child->stop(0);
        }
        $this->assertDatabaseCount('backup_write_operations', 1);
        $this->failure('write_drain_timeout', fn () => (new VerifiedTestCaptureProtection)->capture($this->lease, fn () => $this->fail('Orphan admission ignored')));
        $this->assertDatabaseCount('backup_write_operations', 1);
        $this->assertDatabaseHas('backup_write_test_evidence', ['balance' => '123.4567', 'quantity' => 9]);
        $this->assertSame('open', app(WriteCoordinationStore::class)->status()['state']);
    }

    public function test_killed_capture_worker_does_not_reopen_its_barrier_at_lease_expiry(): void
    {
        $code = $this->childBootstrap().<<<'PHP'
        $row = App\Modules\BackupRestore\Models\BackupOperationLease::query()->findOrFail('global');
        $lease = new App\Modules\BackupRestore\Services\BackupLease($row->operation_uuid,$row->owner_token,$row->fencing_number);
        (new Tests\Support\VerifiedTestCaptureProtection)->capture($lease,function(): void {
            echo "CAPTURING\n"; fflush(STDOUT);
            sleep(20);
        });
        PHP;
        $child = new Process([PHP_BINARY, '-r', $code], base_path());
        $child->setTimeout(30)->start();
        try {
            $this->waitUntil(fn (): bool => str_contains($child->getOutput(), 'CAPTURING'));
        } finally {
            $child->stop(0);
        }
        DB::table('backup_write_barriers')->update(['deadline_at' => now('UTC')->subMinute()]);
        DB::table('backup_operation_leases')->update(['expires_at' => now('UTC')->subMinute()]);
        $this->failure('write_barrier_active', fn () => (new WriteAdmission)->begin('http'));
        $this->failure('write_barrier_recovery_required', fn () => (new CoordinatedCaptureProtection)->recover($this->actor));
        $this->assertSame('recovery_required', app(WriteCoordinationStore::class)->status()['state']);
    }

    public function test_actual_database_worker_defers_before_reservation_without_consuming_attempts(): void
    {
        config(['backup-restore.capture_timeout' => 45]);
        AdmittedQueueProbe::dispatch()->onConnection('database')->onQueue('default');
        $run = function (): void {
            $worker = new Process([PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=default', '--once', '--sleep=0', '--tries=1', '--no-interaction'],
                base_path(), ['BACKUP_WRITE_ADMISSION_ENABLED' => 'true']);
            $worker->setTimeout(30)->run();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
        };
        (new VerifiedTestCaptureProtection)->capture($this->lease, function () use ($run): void {
            $run();
            $this->assertDatabaseHas('jobs', ['queue' => 'default', 'attempts' => 0, 'reserved_at' => null]);
            $this->assertDatabaseCount('failed_jobs', 0);
            $this->assertDatabaseHas('backup_write_test_evidence', ['quantity' => 9]);
        });
        $run();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('backup_write_operations', 0);
        $this->assertDatabaseHas('backup_write_test_evidence', ['balance' => '123.4567', 'quantity' => 10]);
    }

    private function childBootstrap(): string
    {
        return <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (!app()->environment('testing') || !str_starts_with(Illuminate\Support\Facades\DB::connection()->getDatabaseName(),'codex_public_ids_test_')) {
            throw new LogicException('Disposable test database required');
        }
        config(['backup-restore.write_admission_enabled'=>true,'backup-restore.drain_timeout'=>2,'backup-restore.capture_timeout'=>15]);

        PHP;
    }

    private function waitUntil(\Closure $ready): void
    {
        $deadline = microtime(true) + 20;
        while (! $ready()) {
            if (microtime(true) >= $deadline) {
                $this->fail('Disposable child did not reach its synchronization point.');
            }
            usleep(20000);
        }
    }

    private function crashedBarrier(): void
    {
        app(WriteCoordinationStore::class)->locked(function (Connection $connection): void {
            $connection->table('backup_write_barriers')->update(['state' => 'capturing', 'fence' => 10, 'run_uuid' => $this->lease->runUuid,
                'lease_fence' => $this->lease->fence, 'owner_hash' => hash('sha256', $this->lease->owner), 'deadline_at' => now('UTC')->subMinute()]);
            $connection->table('backup_operation_leases')->where('scope', 'global')->update(['expires_at' => now('UTC')->subMinute()]);
        });
    }

    private function failure(string $code, \Closure $action): void
    {
        try {
            $action();
            $this->fail('Expected safe failure: '.$code);
        } catch (BackupFailure $failure) {
            $this->assertSame($code, $failure->getMessage());
            $this->assertNull($failure->getPrevious());
        }
    }
}
