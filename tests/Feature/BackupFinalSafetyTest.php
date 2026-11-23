<?php

namespace Tests\Feature;

use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\BackupKeyProvider;
use App\Modules\BackupRestore\Services\CaptureProtection;
use App\Modules\BackupRestore\Services\LocalRecoveryReadiness;
use App\Modules\Products\Filament\Resources\Brands\Schemas\BrandForm;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema as FormSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Tests\Concerns\BackupFileEnvironment;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class BackupFinalSafetyTest extends TestCase
{
    use BackupFileEnvironment;
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackupFiles();
        Http::preventStrayRequests();
        Schema::create('phase6b_business_probe', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('amount');
        });
        DB::table('phase6b_business_probe')->insert(['id' => 1, 'amount' => 0]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('phase6b_business_probe');
        $this->removeBackupTestDirectory($this->backupTestRoot);
        parent::tearDown();
    }

    public function test_ordinary_sql_uses_existing_transactions_without_backup_interception(): void
    {
        DB::transaction(function (): void {
            DB::table('phase6b_business_probe')->increment('amount');
        });
        $this->assertSame(1, (int) DB::table('phase6b_business_probe')->value('amount'));
        $this->assertSame(['capture_protection_unavailable'], app(CaptureProtection::class)->blockers());
    }

    public function test_brand_upload_uses_the_validated_standard_component(): void
    {
        $owner = new class extends Component implements HasSchemas
        {
            use InteractsWithSchemas;
        };
        $schema = BrandForm::configure(FormSchema::make($owner));
        $upload = $schema->getComponent(fn ($component): bool => $component instanceof FileUpload && $component->getName() === 'icon', withHidden: true);
        $this->assertInstanceOf(FileUpload::class, $upload);
        $this->assertSame(FileUpload::class, $upload::class);
        $this->assertSame(2048, $upload->getMaxSize());
        $this->assertSame(['image/jpeg', 'image/png', 'image/webp'], $upload->getAcceptedFileTypes());
    }

    public function test_encryption_keys_inside_public_web_or_recovery_roots_are_rejected(): void
    {
        mkdir($this->backupTestRoot.'/web');
        $this->app->usePublicPath($this->backupTestRoot.'/web');
        mkdir($this->backupTestRoot.'/recovery');
        config(['backup-restore.restore.root' => $this->backupTestRoot.'/recovery']);
        foreach (['web', 'recovery'] as $directory) {
            $path = $this->backupTestRoot.'/'.$directory.'/v1.key';
            copy($this->backupKeyPath, $path);
            config(['backup-restore.key_files.v1' => $path]);
            $this->failure('key_unavailable', fn () => app(BackupKeyProvider::class)->withKey('v1', fn (): bool => true));
        }
    }

    public function test_readiness_rejects_media_overlap_and_does_not_create_workspaces(): void
    {
        $path = $this->backupTestRoot.'/public/should-not-exist';
        config(['backup-restore.workspace_root' => $path]);
        $report = app(LocalRecoveryReadiness::class)->inspect();
        $this->assertSame('unsafe_path', $report['checks']['backup_workspace']['reason']);
        $this->assertDirectoryDoesNotExist($path);
        $this->assertCount(6, $report['readiness']);
        $this->assertFalse($report['manual_backup_ready']);
        $this->assertSame('FHBK1', $report['checks']['key_format']['envelope_format']);
        $this->assertTrue($report['checks']['key_format']['identifier_valid']);
    }

    public function test_readiness_rejects_relative_traversal_in_workspace_without_writing(): void
    {
        config(['backup-restore.workspace_root' => $this->backupTestRoot.'/public/../work']);
        $report = app(LocalRecoveryReadiness::class)->inspect();
        $this->assertSame('unsafe_path', $report['checks']['backup_workspace']['reason']);
        $this->assertDirectoryDoesNotExist($this->backupTestRoot.'/work');
    }

    public function test_backup_queue_delivery_requires_the_configured_database_connection(): void
    {
        config(['database.connections.phase6b_queue_alias' => config('database.connections.'.config('database.default')),
            'queue.connections.backup_database.connection' => 'phase6b_queue_alias']);
        $report = app(LocalRecoveryReadiness::class)->inspect();
        $this->assertSame('blocked', $report['checks']['capture']['status']);
        $this->assertSame('blocked', $report['checks']['backup_queue']['status']);
        $this->assertArrayNotHasKey('worker_isolation', $report['checks']['backup_queue']);
    }

    public function test_disposable_pipeline_does_not_remove_real_engine_capture_blockers(): void
    {
        $blockers = app(CaptureProtection::class)->blockers();
        $this->assertSame(['capture_protection_unavailable'], $blockers);
        $this->assertDatabaseCount('backup_runs', 0);
        $this->assertDatabaseCount('backup_restore_runs', 0);
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
