<?php

namespace Tests\Feature;

use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\QueryWriteGuard;
use App\Modules\BackupRestore\Services\WriteAdmission;
use App\Modules\BackupRestore\Services\WriteCoordinationStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WriteAdmissionPerformanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(33316, (int) DB::connection()->getConfig('port'));
        $this->assertStringStartsWith('codex_public_ids_test_', DB::connection()->getDatabaseName());
        (require app_path('Modules/BackupRestore/Database/Migrations/2026_11_13_045833_create_backup_write_coordination_tables.php'))->up();
        DB::statement('CREATE TABLE admission_performance_probe (id int PRIMARY KEY, amount int NOT NULL) ENGINE=InnoDB');
        DB::table('admission_performance_probe')->insert(['id' => 1, 'amount' => 0]);
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
        foreach (['admission_performance_probe', 'backup_write_operations', 'backup_write_barriers'] as $table) {
            DB::statement('DROP TABLE `'.$table.'`');
        }
        parent::tearDown();
    }

    public function test_profile_guard_queries_with_nested_transactions_sync_and_after_commit_work(): void
    {
        $counts = [];
        DB::listen(function ($query) use (&$counts): void {
            $category = self::category($query->sql, $query->connectionName);
            $phase = 'business';
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 35) as $frame) {
                if (($frame['class'] ?? '') === WriteAdmission::class && in_array($frame['function'], ['assertHeld', 'begin', 'finish'], true)) {
                    $phase = $frame['function'];
                    break;
                }
            }
            $key = $phase.'/'.$category;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        });
        app(WriteAdmission::class)->run('service', function (): void {
            DB::transaction(function (): void {
                DB::transaction(fn () => DB::table('admission_performance_probe')->increment('amount'));
                Bus::dispatchSync(new class
                {
                    public function handle(): void
                    {
                        DB::table('admission_performance_probe')->increment('amount');
                    }
                });
                DB::afterCommit(fn () => Bus::dispatchSync(new class
                {
                    public function handle(): void
                    {
                        DB::table('admission_performance_probe')->increment('amount');
                    }
                }));
            });
        });
        ksort($counts);
        $label = getenv('ADMISSION_PROFILE_LABEL') === 'before' ? 'before' : 'after';
        file_put_contents(storage_path('framework/testing/phase6c3a/'.$label.'-guard-profile.json'), json_encode($counts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->assertSame(3, (int) DB::table('admission_performance_probe')->value('amount'));
        $this->assertDatabaseCount('backup_write_operations', 0);
    }

    #[DataProvider('revocations')]
    public function test_every_mutation_revalidates_the_owner_fence_and_barrier(string $revocation): void
    {
        $admission = app(WriteAdmission::class);
        if ($revocation === 'barrier_fence') {
            app(WriteCoordinationStore::class)->locked(fn ($connection) => $connection->table('backup_write_barriers')->update(['fence' => 10]));
        }
        $admission->begin('service');
        DB::table('admission_performance_probe')->increment('amount');
        $coordination = app(WriteCoordinationStore::class)->connection();
        match ($revocation) {
            'owner' => $coordination->table('backup_write_operations')->update(['owner_hash' => str_repeat('a', 64)]),
            'operation_fence' => $coordination->table('backup_write_operations')->update(['admitted_fence' => 100]),
            'missing_operation' => $coordination->table('backup_write_operations')->whereNotNull('uuid')->delete(),
            'barrier_fence' => $coordination->table('backup_write_barriers')->update(['fence' => 9]),
            'capturing', 'recovery_required' => $coordination->table('backup_write_barriers')->update(['state' => $revocation]),
        };
        try {
            DB::table('admission_performance_probe')->increment('amount');
            $this->fail('Revoked admission must reject the next mutation.');
        } catch (BackupFailure $failure) {
            $this->assertSame('write_admission_required', $failure->getMessage());
        }
        $this->assertSame(1, (int) DB::table('admission_performance_probe')->value('amount'));
    }

    public function test_unadmitted_and_new_writers_at_a_closed_barrier_are_rejected(): void
    {
        try {
            DB::table('admission_performance_probe')->increment('amount');
            $this->fail('Unadmitted mutation accepted.');
        } catch (BackupFailure $failure) {
            $this->assertSame('write_admission_required', $failure->getMessage());
        }
        app(WriteCoordinationStore::class)->locked(fn ($connection) => $connection->table('backup_write_barriers')->update(['state' => 'capturing']));
        try {
            app(WriteAdmission::class)->begin('service');
            $this->fail('Closed barrier accepted a writer.');
        } catch (BackupFailure $failure) {
            $this->assertSame('write_barrier_active', $failure->getMessage());
        }
        $this->assertDatabaseCount('backup_write_operations', 0);
        $this->assertSame(0, (int) DB::table('admission_performance_probe')->value('amount'));
    }

    public function test_an_unfinished_transaction_retains_its_durable_writer(): void
    {
        $admission = app(WriteAdmission::class);
        $admission->begin('service');
        DB::beginTransaction();
        DB::table('admission_performance_probe')->increment('amount');
        try {
            $admission->finish();
            $this->fail('Open transaction lost its durable writer.');
        } catch (BackupFailure $failure) {
            $this->assertSame('write_transactions_open', $failure->getMessage());
        }
        $this->assertDatabaseCount('backup_write_operations', 1);
        DB::rollBack();
        $admission->finish();
        $this->assertDatabaseCount('backup_write_operations', 0);
    }

    public function test_unchanged_heartbeat_falls_back_to_current_ownership_without_false_rejection(): void
    {
        $admission = app(WriteAdmission::class);
        $admission->begin('service');
        $connection = app(WriteCoordinationStore::class)->connection();
        $pdo = $connection->getPdo();
        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            if (self::category($query->sql, $query->connectionName) === 'writer_read') {
                $reads++;
            }
        });
        try {
            /** Freeze only the disposable coordination session clock to force a zero-changed-row UPDATE. */
            $pdo->exec('SET timestamp=1700000000');
            DB::table('admission_performance_probe')->increment('amount');
            DB::table('admission_performance_probe')->increment('amount');
            $this->assertSame(1, $reads);
            $connection->table('backup_write_operations')->update(['owner_hash' => str_repeat('a', 64)]);
            try {
                DB::table('admission_performance_probe')->increment('amount');
                $this->fail('Unchanged timestamps must not authorize a stolen writer.');
            } catch (BackupFailure $failure) {
                $this->assertSame('write_admission_required', $failure->getMessage());
            }
            $this->assertSame(2, (int) DB::table('admission_performance_probe')->value('amount'));
        } finally {
            $pdo->exec('SET timestamp=0');
        }
    }

    #[DataProvider('jobBoundaries')]
    public function test_sync_and_after_commit_jobs_reject_a_lost_owner_before_job_effects(bool $afterCommit): void
    {
        app(WriteAdmission::class)->begin('service');
        try {
            DB::transaction(function () use ($afterCommit): void {
                DB::table('admission_performance_probe')->increment('amount');
                $job = new class
                {
                    public function handle(): void
                    {
                        DB::table('admission_performance_probe')->increment('amount', 100);
                    }
                };
                app(WriteCoordinationStore::class)->connection()->table('backup_write_operations')->update(['owner_hash' => str_repeat('a', 64)]);
                if ($afterCommit) {
                    DB::afterCommit(fn () => Bus::dispatchSync($job));
                } else {
                    Bus::dispatchSync($job);
                }
            });
            $this->fail('Revoked job was acknowledged.');
        } catch (BackupFailure $failure) {
            $this->assertSame('write_admission_required', $failure->getMessage());
        }
        $this->assertSame($afterCommit ? 1 : 0, (int) DB::table('admission_performance_probe')->value('amount'));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('backup_write_operations', 1);
    }

    public static function jobBoundaries(): array
    {
        return [[false], [true]];
    }

    public static function revocations(): array
    {
        return [['owner'], ['operation_fence'], ['missing_operation'], ['barrier_fence'], ['capturing'], ['recovery_required']];
    }

    public static function category(string $sql, string $connection): string
    {
        if (str_contains($sql, 'SELECT DATABASE() AS db, @@port AS port')) {
            return 'source_identity';
        }
        if ($connection !== 'backup_write_coordination') {
            return 'business';
        }
        if (str_contains($sql, 'backup_write_barriers')) {
            return str_starts_with($sql, 'select') ? 'barrier_lock' : 'barrier_initialize';
        }
        if (str_contains($sql, 'backup_write_operations')) {
            return match (strtolower(strtok($sql, ' '))) {
                'select' => 'writer_read', 'insert' => 'writer_acquire', 'delete' => 'writer_release', default => 'writer_heartbeat',
            };
        }

        return 'coordination_other';
    }
}
