<?php

namespace Tests\Feature;

use App\Modules\BackupRestore\Jobs\CreateFullBackup;
use App\Modules\BackupRestore\Jobs\VerifyIsolatedRestore;
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\BackupQueueIsolation;
use App\Modules\BackupRestore\Services\CaptureProtection;
use App\Modules\BackupRestore\Services\CgroupProcessFence;
use App\Modules\BackupRestore\Services\QueryWriteGuard;
use App\Modules\BackupRestore\Services\WriteAdmission;
use App\Modules\BackupRestore\Services\WriteCoordinationStore;
use App\Modules\BackupRestore\Services\WriteProtectionHooks;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\AdmittedQueueProbe;
use Tests\Support\CrashingQueueProbe;
use Tests\TestCase;

class WriterQueueContainmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(33316, (int) DB::connection()->getConfig('port'));
        $this->assertStringStartsWith('codex_public_ids_test_', DB::connection()->getDatabaseName());
        (require app_path('Modules/BackupRestore/Database/Migrations/2026_11_13_045833_create_backup_write_coordination_tables.php'))->up();
        (require app_path('Modules/AccessManagement/Database/Migrations/0001_01_01_000002_create_jobs_table.php'))->up();
        DB::statement('CREATE TABLE backup_write_test_evidence (id int PRIMARY KEY, quantity int NOT NULL) ENGINE=InnoDB');
        DB::table('backup_write_test_evidence')->insert(['id' => 1, 'quantity' => 0]);
        app(QueryWriteGuard::class)->install();
        app(WriteCoordinationStore::class)->connection();
        config(['backup-restore.write_admission_enabled' => true]);
    }

    protected function tearDown(): void
    {
        config(['backup-restore.write_admission_enabled' => false]);
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::purge('backup_write_coordination');
        foreach (['backup_write_test_evidence', 'jobs', 'job_batches', 'failed_jobs', 'backup_write_operations', 'backup_write_barriers'] as $table) {
            DB::statement('DROP TABLE `'.$table.'`');
        }
        parent::tearDown();
    }

    public function test_retried_or_subclassed_control_jobs_are_rejected_before_any_handler(): void
    {
        $ran = false;
        $jobs = [new CreateFullBackup('11111111-1111-4111-8111-111111111111'), new VerifyIsolatedRestore('11111111-1111-4111-8111-111111111111'), new class('11111111-1111-4111-8111-111111111111') extends CreateFullBackup {}];
        foreach ([1, 2] as $attempt) {
            foreach ($jobs as $job) {
                try {
                    app(WriteProtectionHooks::class)->handle($job, function () use (&$ran): void {
                        $ran = true;
                    });
                    $this->fail('Control payload ran in an ordinary admitted worker.');
                } catch (BackupFailure $failure) {
                    $this->assertSame('backup_queue_isolation_unverified', $failure->getMessage());
                }
            }
        }
        $this->assertFalse($ran);
        $this->assertDatabaseCount('backup_write_operations', 0);
    }

    public function test_control_queue_route_is_denied_before_reservation_or_durable_admission(): void
    {
        foreach (['backups', 'default,backup-restores'] as $queues) {
            try {
                app(WriteProtectionHooks::class)->pop(fn () => $this->fail('Reserved a forbidden queue.'), $queues);
                $this->fail('Control route accepted.');
            } catch (BackupFailure $failure) {
                $this->assertSame('backup_queue_isolation_unverified', $failure->getMessage());
            }
        }
        $this->assertDatabaseCount('backup_write_operations', 0);
    }

    public function test_ordinary_job_reservation_release_and_retry_remain_admitted(): void
    {
        $hooks = app(WriteProtectionHooks::class);
        foreach ([1, 2] as $attempt) {
            $job = Mockery::mock(Job::class);
            $this->assertSame($job, $hooks->pop(function () use ($job): Job {
                $this->assertDatabaseCount('backup_write_operations', 1);
                DB::table('backup_write_test_evidence')->increment('quantity');

                return $job;
            }, 'default'));
            $this->assertDatabaseCount('backup_write_operations', 1);
            $hooks->attempted(new JobAttempted('database', $job));
            $this->assertDatabaseCount('backup_write_operations', 0);
        }
        $this->assertSame(2, (int) DB::table('backup_write_test_evidence')->value('quantity'));
    }

    public function test_external_pdo_writer_demonstrates_the_unresolved_global_coverage_gap(): void
    {
        app(WriteCoordinationStore::class)->locked(fn ($connection) => $connection->table('backup_write_barriers')->update(['state' => 'capturing']));
        $source = DB::connection()->getDatabaseName();
        $external = new PDO('mysql:host=127.0.0.1;port=33316;dbname='.$source, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $external->exec('UPDATE backup_write_test_evidence SET quantity=7 WHERE id=1');
        $this->assertSame(7, (int) DB::table('backup_write_test_evidence')->value('quantity'));
        $this->assertContains('external_writers_unverified', app(CaptureProtection::class)->blockers());
        $this->assertContains('backup_queue_isolation_unverified', app(CaptureProtection::class)->blockers());
    }

    public function test_real_ordinary_worker_defers_before_reservation_and_later_commits(): void
    {
        app(WriteAdmission::class)->run('service', fn () => Bus::dispatch((new AdmittedQueueProbe)->onConnection('database')->onQueue('default')));
        $coordination = app(WriteCoordinationStore::class)->connection();
        $coordination->table('backup_write_barriers')->where('scope', 'global')->update(['state' => 'capturing']);
        $worker = fn (): Process => new Process([PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=default', '--once', '--sleep=0', '--timeout=60', '--no-interaction'], base_path(), ['BACKUP_WRITE_ADMISSION_ENABLED' => 'true']);
        $first = $worker();
        $first->setTimeout(15)->mustRun();
        $this->assertDatabaseHas('jobs', ['queue' => 'default', 'attempts' => 0, 'reserved_at' => null]);
        $this->assertDatabaseCount('failed_jobs', 0);
        $coordination->table('backup_write_barriers')->where('scope', 'global')->update(['state' => 'open']);
        $second = $worker();
        $second->setTimeout(15)->mustRun();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('backup_write_operations', 0);
        $this->assertSame(1, (int) DB::table('backup_write_test_evidence')->value('quantity'));
    }

    public function test_windows_process_fencing_remains_unavailable_and_queue_inspection_adds_no_queries(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows local capability check.');
        }
        $fence = new CgroupProcessFence;
        $this->assertNull($fence->identity('11111111-1111-4111-8111-111111111111', 1));
        $this->assertFalse($fence->stop((object) ['process_identity' => null]));
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $started = hrtime(true);
        for ($index = 0; $index < 100; $index++) {
            app(BackupQueueIsolation::class)->assertOrdinaryRoute('database', 'default,reporting');
            $report = app(BackupQueueIsolation::class)->inspect();
        }
        $elapsed = (hrtime(true) - $started) / 1_000_000;
        $this->assertSame(0, $queries);
        $this->assertSame('blocked', $report['status']);
        file_put_contents(storage_path('framework/testing/phase6c3b/queue-foundation.json'), json_encode(['iterations' => 100, 'queries' => $queries, 'milliseconds' => $elapsed, 'assessment' => $report, 'windows_fence_supported' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    #[DataProvider('crashPhases')]
    public function test_real_worker_crash_preserves_unacknowledged_job_and_durable_writer(string $phase, int $quantity): void
    {
        app(WriteAdmission::class)->run('service', fn () => Bus::dispatch((new CrashingQueueProbe($phase))->onConnection('database')->onQueue('default')));
        $worker = new Process([PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=default', '--once', '--sleep=0', '--timeout=60', '--no-interaction'], base_path(), ['BACKUP_WRITE_ADMISSION_ENABLED' => 'true']);
        $worker->setTimeout(15)->run();
        $this->assertSame(19, $worker->getExitCode(), $worker->getErrorOutput());
        $this->assertDatabaseHas('jobs', ['queue' => 'default', 'attempts' => 1]);
        $this->assertNotNull(DB::table('jobs')->value('reserved_at'));
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('backup_write_operations', 1);
        $this->assertSame($quantity, (int) DB::table('backup_write_test_evidence')->value('quantity'));
        $coordination = app(WriteCoordinationStore::class)->connection();
        $coordination->table('backup_write_operations')->whereNotNull('uuid')->update(['heartbeat_at' => '2000-01-01 00:00:00']);
        $coordination->table('backup_write_barriers')->where('scope', 'global')->update(['state' => 'draining', 'deadline_at' => '2000-01-01 00:00:00']);
        $this->assertSame(1, app(WriteCoordinationStore::class)->status()['in_flight']);
        $this->assertSame('recovery_required', app(WriteCoordinationStore::class)->status()['state']);
        $external = DB::connection()->getPdo();
        $external->exec('UPDATE jobs SET reserved_at=1');
        $retry = new Process([PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=default', '--once', '--sleep=0', '--timeout=60', '--no-interaction'], base_path(), ['BACKUP_WRITE_ADMISSION_ENABLED' => 'true']);
        $retry->setTimeout(15)->mustRun();
        $this->assertDatabaseHas('jobs', ['attempts' => 1, 'reserved_at' => 1]);
        $this->assertDatabaseCount('backup_write_operations', 1);
        $this->assertSame($quantity, (int) DB::table('backup_write_test_evidence')->value('quantity'));
    }

    public static function crashPhases(): array
    {
        return [['after_reservation', 0], ['open_transaction', 0], ['after_commit', 1]];
    }
}
