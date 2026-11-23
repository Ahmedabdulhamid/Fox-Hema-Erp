<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\BackupRestore\Jobs\CreateFullBackup;
use App\Modules\BackupRestore\Models\BackupRun;
use App\Modules\BackupRestore\Services\BackupCoordinator;
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\BackupLease;
use App\Modules\BackupRestore\Services\BackupReadiness;
use App\Modules\BackupRestore\Services\BackupTransport;
use App\Modules\BackupRestore\Services\BackupWorkspace;
use App\Modules\BackupRestore\Services\CaptureProtection;
use App\Modules\BackupRestore\Services\DatabaseDumper;
use App\Modules\BackupRestore\Services\FileSnapshot;
use App\Modules\BackupRestore\Services\UnavailableCaptureProtection;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\Support\QueueRemovalProbe;
use Tests\TestCase;

class BackupAdmissionRemovalTest extends TestCase
{
    use UsesIsolatedDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(33316, (int) DB::connection()->getConfig('port'));
        $this->assertStringStartsWith('codex_public_ids_test_', DB::connection()->getDatabaseName());
        app(ModuleRegistry::class)->register();
        Module::query()->where('code', 'backup_restore')->update(['is_active' => true]);
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        if (! Schema::hasTable('backup_removal_probe')) {
            Schema::create('backup_removal_probe', function (Blueprint $table): void {
                $table->integer('id')->primary();
                $table->integer('amount');
            });
        }
        DB::table('backup_removal_probe')->insert(['id' => 1, 'amount' => 0]);
    }

    public function test_all_capture_contract_and_low_level_paths_remain_blocked(): void
    {
        config(['backup-restore.write_admission_enabled' => true, 'backup-restore.write_gate_enabled' => true]);
        $protection = app(CaptureProtection::class);
        $this->assertInstanceOf(UnavailableCaptureProtection::class, $protection);
        $this->assertSame(['capture_protection_unavailable'], $protection->blockers());
        $lease = new BackupLease((string) Str::uuid(), bin2hex(random_bytes(32)), 1);
        $workspace = new BackupWorkspace(storage_path('framework/testing/not-created-removal-workspace'));
        foreach ([fn () => $protection->capture($lease, fn () => $this->fail('Capture executed')),
            fn () => $protection->assertHeld($lease), fn () => $protection->mode(),
            fn () => app(DatabaseDumper::class)->dump($workspace, $lease), fn () => app(FileSnapshot::class)->capture($workspace, $lease)] as $operation) {
            $this->failure('capture_protection_unavailable', $operation);
        }
        $this->assertDirectoryDoesNotExist($workspace->path);
    }

    public function test_manual_scheduled_and_queued_backups_cannot_capture_or_upload(): void
    {
        $this->mock(BackupTransport::class)->shouldNotReceive('upload');
        $coordinator = app(BackupCoordinator::class);
        $this->assertContains('capture_protection_unavailable', app(BackupReadiness::class)->blockers());
        $this->failure('capture_protection_unavailable', fn () => $coordinator->request($this->actor, (string) Str::uuid()));
        $run = BackupRun::factory()->create(['requested_by' => $this->actor->id]);
        Bus::dispatchSync(new CreateFullBackup($run->uuid));
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('capture_protection_unavailable', $run->fresh()->failure_code);
        $this->assertDatabaseCount('backup_operation_leases', 0);
        $this->assertFalse(config('backup-restore.automation.controller_enabled'));
        $this->assertFalse(config('backup-restore.automation.scheduling_approved'));
        $this->assertFalse(config('backup-restore.restore.enabled'));
    }

    public function test_standard_http_kernel_and_sql_work_without_admission_even_with_legacy_quarantine(): void
    {
        $this->assertSame(Kernel::class, app(HttpKernel::class)::class);
        DB::table('backup_write_barriers')->insert(['scope' => 'global', 'state' => 'capturing', 'fence' => 1]);
        config(['backup-restore.write_admission_enabled' => true]);
        Route::match(['GET', 'POST'], '/testing/backup-removal', function () {
            DB::transaction(fn () => DB::table('backup_removal_probe')->where('id', 1)->increment('amount'));

            return response()->json(['ok' => true]);
        });
        $this->getJson('/testing/backup-removal')->assertOk();
        $this->postJson('/testing/backup-removal')->assertOk();
        $this->assertSame(2, DB::table('backup_removal_probe')->value('amount'));
        $this->assertDatabaseCount('backup_write_operations', 0);
        $this->assertDatabaseHas('backup_write_barriers', ['state' => 'capturing']);
    }

    public function test_ordinary_database_worker_runs_without_backup_pop_hooks(): void
    {
        Bus::dispatch((new QueueRemovalProbe)->onConnection('database')->onQueue('default'));
        $worker = new Process([PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=default', '--once', '--sleep=0', '--no-interaction'],
            base_path(), ['BACKUP_WRITE_ADMISSION_ENABLED' => 'true']);
        $worker->setTimeout(15)->mustRun();
        $this->assertSame(1, DB::table('backup_removal_probe')->value('amount'));
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('backup_write_operations', 0);
    }

    public function test_legacy_quarantine_is_never_reset_or_cleaned_by_recovery(): void
    {
        DB::table('backup_write_barriers')->insert(['scope' => 'global', 'state' => 'capturing', 'fence' => 7]);
        $this->failure('write_barrier_recovery_required', fn () => app(BackupCoordinator::class)->recover($this->actor));
        $this->assertDatabaseHas('backup_write_barriers', ['state' => 'capturing', 'fence' => 7]);
        DB::table('backup_write_barriers')->where('scope', 'global')->update(['state' => 'open']);
        DB::table('backup_write_operations')->insert(['uuid' => (string) Str::uuid(), 'owner_hash' => str_repeat('a', 64),
            'kind' => 'queue', 'admitted_fence' => 7, 'started_at' => now()->subYear(), 'heartbeat_at' => now()->subYear()]);
        $this->failure('write_barrier_recovery_required', fn () => app(BackupCoordinator::class)->recover($this->actor));
        $this->assertDatabaseCount('backup_write_operations', 1);
    }

    private function failure(string $reason, \Closure $callback): void
    {
        try {
            $callback();
            $this->fail('Expected '.$reason);
        } catch (BackupFailure $failure) {
            $this->assertSame($reason, $failure->getMessage());
        }
    }
}
