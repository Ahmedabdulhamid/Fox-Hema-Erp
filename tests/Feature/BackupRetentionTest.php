<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\BackupRestore\Models\BackupDestination;
use App\Modules\BackupRestore\Models\BackupOperationLease;
use App\Modules\BackupRestore\Models\BackupPolicy;
use App\Modules\BackupRestore\Models\BackupRestoreRun;
use App\Modules\BackupRestore\Models\BackupRun;
use App\Modules\BackupRestore\Services\BackupCoordinator;
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\BackupObjectDeletion;
use App\Modules\BackupRestore\Services\BackupReadiness;
use App\Modules\BackupRestore\Services\R2Storage;
use App\Modules\BackupRestore\Services\RetentionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Concerns\BackupFileEnvironment;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\Support\BackupS3Fake;
use Tests\Support\FakeBackupR2Storage;
use Tests\TestCase;

class BackupRetentionTest extends TestCase
{
    use BackupFileEnvironment;
    use UsesIsolatedDatabase;

    private User $actor;

    private BackupDestination $destination;

    private BackupS3Fake $s3;

    private string $fakeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackupFiles();
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:05:00', 'UTC'));
        app(ModuleRegistry::class)->register();
        Module::query()->where('code', 'backup_restore')->update(['is_active' => true]);
        $this->actor = $this->provisionTestSuperAdmin();
        $this->destination = BackupDestination::factory()->create(['bucket' => 'fox-hema-backups', 'prefix' => 'erp-backups', 'probe_status' => 'passed']);
        BackupPolicy::factory()->create(['destination_id' => $this->destination->id, 'retention_requested' => true,
            'minimum_verified' => 2, 'maximum_backups' => 2, 'retention_days' => 1]);
        $this->s3 = new BackupS3Fake(Storage::fake('backup-engine-r2-'.bin2hex(random_bytes(8))));
        $this->fakeRoot = $this->s3->disk->path('');
        app()->instance(R2Storage::class, new FakeBackupR2Storage($this->s3));
        $readiness = Mockery::mock(BackupReadiness::class)->makePartial();
        $readiness->shouldReceive('blockers')->andReturn([]);
        app()->instance(BackupReadiness::class, $readiness);
        config(['backup-restore.automation.approved_deletion_scope' => $this->destination->only(['endpoint', 'bucket', 'prefix'])]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->removeBackupTestDirectory($this->fakeRoot);
        $this->removeBackupTestDirectory($this->backupTestRoot);
        parent::tearDown();
    }

    private function approve(): void
    {
        config(['backup-restore.automation.retention_deletion_approved' => true, 'backup-restore.automation.conditional_delete_verified' => true]);
    }

    private function available(int $age, array $overrides = []): BackupRun
    {
        $uuid = (string) Str::uuid();
        $attempt = (string) Str::uuid();
        $bytes = 'isolated-'.$uuid;
        $key = 'runs/'.$uuid.'/attempt-'.$attempt.'.fhbk';
        $run = BackupRun::factory()->create(array_merge(['uuid' => $uuid, 'destination_id' => $this->destination->id,
            'status' => 'available', 'verification_status' => 'checksum_verified', 'verified_at' => now()->subDays($age),
            'completed_at' => now()->subDays($age), 'object_key' => $key, 'size_bytes' => strlen($bytes),
            'ciphertext_checksum' => hash('sha256', $bytes), 'manifest_checksum' => str_repeat('b', 64),
            'destination_snapshot' => $this->destination->only(['endpoint', 'bucket', 'prefix']) + [
                'attempt_uuid' => $attempt, 'database_name' => DB::connection()->getDatabaseName()]], $overrides));
        $fullKey = $this->destination->prefix.'/'.$key;
        $this->s3->disk->put($fullKey, $bytes);
        $this->s3->metadata[$fullKey] = ['application' => 'fox-hema-erp', 'format' => 'fhbk-v1', 'run-uuid' => $uuid,
            'attempt-uuid' => $attempt, 'sha256' => hash('sha256', $bytes)];

        return $run;
    }

    private function copies(): BackupRun
    {
        $this->available(0);
        $this->available(1);

        return $this->available(10);
    }

    public function test_completed_failed_retention_does_not_block_a_new_backup(): void
    {
        $old = $this->copies();
        $this->approve();
        $this->s3->failCommand = 'DeleteObject';
        $this->failure('retention_failed', fn () => app(RetentionService::class)->delete($this->actor, $old->uuid));
        $run = app(BackupCoordinator::class)->request($this->actor, (string) Str::uuid());
        $this->assertSame('queued', $run->status);
        $this->assertSame('deletion_failed', $old->fresh()->status);
    }

    public function test_completed_verification_does_not_block_retention(): void
    {
        $old = $this->copies();
        $this->approve();
        $restore = BackupRestoreRun::factory()->create(['backup_run_id' => BackupRun::query()->where('uuid', '!=', $old->uuid)->firstOrFail()->id,
            'mode' => 'verify_only', 'status' => 'verified', 'verification_findings' => ['isolated_restoration_verified' => true]]);
        BackupOperationLease::factory()->create(['operation_uuid' => $restore->uuid, 'operation_type' => 'verification', 'expires_at' => now()->subMinute()]);
        app(RetentionService::class)->delete($this->actor, $old->uuid);
        $this->assertSame('deleted', $old->fresh()->status);
    }

    public function test_recovery_reconciles_an_interrupted_delete_without_deleting_again(): void
    {
        $old = $this->interruptedDeletion(true);
        $commands = $this->s3->commands;
        config(['backup-restore.automation.retention_deletion_approved' => false]);
        $result = app(BackupCoordinator::class)->recover($this->actor);
        $this->assertSame(1, $result['recovered']);
        $this->assertSame('deleted', $old->fresh()->status);
        $this->assertSame('deleted', DB::table('backup_deletions')->where('backup_run_id', $old->id)->value('status'));
        $this->assertSame(1, DB::table('backup_deletions')->where('backup_run_id', $old->id)->value('attempts'));
        $this->assertSame(2, BackupRun::query()->where('status', 'available')->count());
        $this->assertSame(['HeadObject'], array_slice($this->s3->commands, count($commands)));
        $audits = DB::table('auth_events')->where('event', 'backups.retention_deleted')->count();
        $this->assertSame(1, $audits);
        $this->assertSame(0, app(BackupCoordinator::class)->recover($this->actor)['recovered']);
        $this->assertSame($audits, DB::table('auth_events')->where('event', 'backups.retention_deleted')->count());
        $this->approve();
        app(RetentionService::class)->delete($this->actor, $old->uuid);
        $this->assertSame($audits, DB::table('auth_events')->where('event', 'backups.retention_deleted')->count());
        $this->assertSame('queued', app(BackupCoordinator::class)->request($this->actor, (string) Str::uuid())->status);
    }

    #[DataProvider('unresolvedDeletionStates')]
    public function test_uncertain_interrupted_deletions_remain_quarantined(string $state): void
    {
        $old = $this->interruptedDeletion($state !== 'present');
        if ($state === 'active') {
            BackupOperationLease::query()->where('scope', 'global')->update(['expires_at' => now()->addMinute()]);
        } elseif ($state === 'missing_etag') {
            DB::table('backup_deletions')->where('backup_run_id', $old->id)->update(['etag' => null]);
        } elseif ($state === 'wrong_fence') {
            DB::table('backup_deletions')->where('backup_run_id', $old->id)->increment('fence');
        } elseif ($state === 'wrong_identity') {
            DB::table('backup_deletions')->where('backup_run_id', $old->id)->update(['object_identity' => '{}']);
        } elseif ($state === 'storage_error') {
            $this->s3->failCommand = 'HeadObject';
        }
        $before = count($this->s3->commands);
        if ($state === 'storage_error') {
            $this->failure('retention_failed', fn () => app(BackupCoordinator::class)->recover($this->actor));
        } else {
            $this->assertSame(0, app(BackupCoordinator::class)->recover($this->actor)['recovered']);
        }
        $this->assertSame('deleting', $old->fresh()->status);
        $this->assertSame('deleting', DB::table('backup_deletions')->where('backup_run_id', $old->id)->value('status'));
        $this->assertNotContains('DeleteObject', array_slice($this->s3->commands, $before));
        $this->failure('lease_busy', fn () => app(BackupCoordinator::class)->request($this->actor, (string) Str::uuid()));
    }

    public static function unresolvedDeletionStates(): array
    {
        return array_map(fn (string $state): array => [$state], ['present', 'active', 'missing_etag', 'wrong_fence', 'wrong_identity', 'storage_error']);
    }

    public function test_recovery_rechecks_ownership_after_remote_inspection(): void
    {
        $old = $this->interruptedDeletion(true);
        $deletion = Mockery::mock(BackupObjectDeletion::class)->makePartial();
        $deletion->shouldReceive('isMissing')->once()->andReturnUsing(function (): bool {
            BackupOperationLease::query()->where('scope', 'global')->increment('fencing_number');

            return true;
        });
        app()->instance(BackupObjectDeletion::class, $deletion);
        $this->assertSame(0, app(BackupCoordinator::class)->recover($this->actor)['recovered']);
        $this->assertSame('deleting', $old->fresh()->status);
    }

    public function test_unauthorized_recovery_cannot_reconcile_or_reach_storage(): void
    {
        $old = $this->interruptedDeletion(true);
        $before = $this->s3->commands;
        try {
            app(RetentionService::class)->recover(User::factory()->create());
            $this->fail('Unauthorized recovery accepted');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, $this->s3->commands);
        $this->assertSame('deleting', $old->fresh()->status);
    }

    public function test_failed_retention_with_mismatched_ownership_does_not_release_the_lease(): void
    {
        $old = $this->interruptedDeletion(false);
        $old->update(['status' => 'deletion_failed']);
        DB::table('backup_deletions')->where('backup_run_id', $old->id)->update(['status' => 'failed', 'owner_token' => str_repeat('a', 64)]);
        $this->failure('lease_busy', fn () => app(BackupCoordinator::class)->request($this->actor, (string) Str::uuid()));
    }

    private function interruptedDeletion(bool $deleted): BackupRun
    {
        $old = $this->copies();
        $this->approve();
        $this->s3->failAfterDelete = $deleted;
        $this->s3->failCommand = $deleted ? null : 'DeleteObject';
        $this->failure('retention_failed', fn () => app(RetentionService::class)->delete($this->actor, $old->uuid));
        $this->s3->failAfterDelete = false;
        $this->s3->failCommand = null;
        $old->update(['status' => 'deleting']);
        DB::table('backup_deletions')->where('backup_run_id', $old->id)->update(['status' => 'deleting', 'failure_code' => null]);
        BackupOperationLease::query()->where('scope', 'global')->update(['expires_at' => now()->subMinute()]);

        return $old;
    }

    public function test_dry_run_is_read_only_and_preserves_the_minimum_verified_copies(): void
    {
        $old = $this->copies();
        $preview = app(RetentionService::class)->preview();
        $this->assertSame([$old->uuid], $preview['eligible']);
        $this->assertFalse($preview['effective']);
        $this->assertSame([], $this->s3->commands);
        $this->assertDatabaseCount('backup_deletions', 0);
        $this->assertDatabaseCount('backup_alerts', 0);
        $this->approve();
        $this->assertFalse(app(RetentionService::class)->preview()['effective']);
    }

    public function test_last_verified_backup_is_never_replaced_by_failed_or_incomplete_backups(): void
    {
        BackupPolicy::query()->findOrFail(1)->update(['minimum_verified' => 1]);
        $this->available(100);
        BackupRun::factory()->create(['status' => 'failed']);
        BackupRun::factory()->create(['status' => 'verifying']);
        $this->assertSame([], app(RetentionService::class)->preview()['eligible']);
        $this->assertSame([], $this->s3->commands);
    }

    public function test_holds_retain_until_and_active_restore_verification_reservations_are_preserved(): void
    {
        $this->copies();
        $held = $this->available(20, ['legal_hold' => true]);
        $retained = $this->available(21, ['retain_until' => now()->addDay()]);
        $reserved = $this->available(22);
        BackupRestoreRun::factory()->create(['backup_run_id' => $reserved->id, 'mode' => 'verify_only', 'status' => 'verifying']);
        $eligible = app(RetentionService::class)->preview()['eligible'];
        foreach ([$held, $retained, $reserved] as $run) {
            $this->assertNotContains($run->uuid, $eligible);
        }
    }

    public function test_deletion_is_disabled_even_for_an_eligible_copy(): void
    {
        $old = $this->copies();
        $this->failure('automation_disabled', fn () => app(RetentionService::class)->delete($this->actor, $old->uuid));
        $this->assertSame([], $this->s3->commands);
        $this->assertDatabaseCount('backup_deletions', 0);
    }

    public function test_low_level_adapter_cannot_delete_an_unclaimed_available_artifact(): void
    {
        $old = $this->copies();
        $this->approve();
        $this->failure('retention_ineligible', fn () => app(BackupObjectDeletion::class)->delete($old, null, fn (string $etag) => null));
        $this->failure('automation_disabled', fn () => app(RetentionService::class)->prune());
        $this->assertSame([], $this->s3->commands);
    }

    public function test_exact_object_deletion_is_audited_and_idempotent(): void
    {
        $old = $this->copies();
        $this->approve();
        $this->s3->disk->put('another-application/keep.txt', 'untouched');
        app(RetentionService::class)->delete($this->actor, $old->uuid);
        $this->assertSame('deleted', $old->fresh()->status);
        $this->assertDatabaseHas('backup_deletions', ['backup_run_id' => $old->id, 'status' => 'deleted', 'attempts' => 1]);
        $this->assertSame('untouched', $this->s3->disk->get('another-application/keep.txt'));
        $commands = $this->s3->commands;
        app(RetentionService::class)->delete($this->actor, $old->uuid);
        $this->assertSame($commands, $this->s3->commands);
        $this->assertSame(2, BackupRun::query()->where('status', 'available')->count());
        foreach (array_filter($this->s3->requests, fn (array $request): bool => $request['name'] === 'DeleteObject') as $request) {
            $this->assertSame('fox-hema-backups', $request['bucket']);
            $this->assertSame('erp-backups/'.$old->object_key, $request['key']);
            $this->assertNotEmpty($request['if_match']);
        }
    }

    public function test_delete_failures_keep_a_durable_identity_and_retry_only_the_same_object(): void
    {
        $old = $this->copies();
        $this->approve();
        $this->s3->failCommand = 'DeleteObject';
        $this->failure('retention_failed', fn () => app(RetentionService::class)->delete($this->actor, $old->uuid));
        $this->assertSame('deletion_failed', $old->fresh()->status);
        $this->assertDatabaseHas('backup_alerts', ['event' => 'retention_failed']);
        $this->s3->failCommand = null;
        app(RetentionService::class)->delete($this->actor, $old->uuid);
        $this->assertDatabaseHas('backup_deletions', ['backup_run_id' => $old->id, 'status' => 'deleted', 'attempts' => 2]);
    }

    public function test_ambiguous_remote_delete_can_reconcile_only_with_a_previously_recorded_etag(): void
    {
        $old = $this->copies();
        $this->approve();
        $this->s3->failAfterDelete = true;
        $this->failure('retention_failed', fn () => app(RetentionService::class)->delete($this->actor, $old->uuid));
        $this->assertNotNull(DB::table('backup_deletions')->where('backup_run_id', $old->id)->value('etag'));
        $this->s3->failAfterDelete = false;
        app(RetentionService::class)->delete($this->actor, $old->uuid);
        $this->assertSame('deleted', $old->fresh()->status);
    }

    public function test_bucket_prefix_and_ownership_mismatch_fail_before_delete(): void
    {
        $old = $this->copies();
        $this->approve();
        $scope = config('backup-restore.automation.approved_deletion_scope');
        foreach (['bucket' => 'learning-platform', 'prefix' => 'erp-backups-other'] as $field => $value) {
            config(['backup-restore.automation.approved_deletion_scope' => array_replace($scope, [$field => $value])]);
            $this->failure('retention_identity_mismatch', fn () => app(RetentionService::class)->delete($this->actor, $old->uuid));
            $this->assertSame([], $this->s3->commands);
        }
        config(['backup-restore.automation.approved_deletion_scope' => $scope]);
        $this->s3->metadata['erp-backups/'.$old->object_key]['application'] = 'other-application';
        $this->failure('retention_identity_mismatch', fn () => app(RetentionService::class)->delete($this->actor, $old->uuid));
        $this->assertNotContains('DeleteObject', $this->s3->commands);
    }

    public function test_unauthorized_deletion_never_reaches_storage(): void
    {
        $old = $this->copies();
        try {
            app(RetentionService::class)->delete(User::factory()->create(), $old->uuid);
            $this->fail('Unauthorized deletion accepted');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame([], $this->s3->commands);
    }

    public function test_expired_foreign_operation_leases_do_not_authorize_a_new_backup(): void
    {
        $old = $this->copies();
        $lease = BackupOperationLease::factory()->create([
            'operation_uuid' => $old->uuid, 'expires_at' => now()->subHour(), 'operation_type' => 'verification']);
        foreach (['verification', 'restore', 'retention'] as $type) {
            $lease->update(['operation_type' => $type]);
            $this->failure('lease_busy', fn () => app(BackupCoordinator::class)->request($this->actor, (string) Str::uuid()));
        }
        $this->assertSame(3, BackupRun::query()->count());
        $this->assertSame([], $this->s3->commands);
    }

    public function test_a_changed_remote_object_cannot_be_deleted_on_retry(): void
    {
        $old = $this->copies();
        $this->approve();
        $this->s3->failCommand = 'DeleteObject';
        $this->failure('retention_failed', fn () => app(RetentionService::class)->delete($this->actor, $old->uuid));
        $this->s3->failCommand = null;
        $this->s3->disk->put('erp-backups/'.$old->object_key, str_repeat('x', $old->size_bytes));
        $before = count(array_filter($this->s3->commands, fn (string $command): bool => $command === 'DeleteObject'));
        $this->failure('retention_identity_mismatch', fn () => app(RetentionService::class)->delete($this->actor, $old->uuid));
        $this->assertSame($before, count(array_filter($this->s3->commands, fn (string $command): bool => $command === 'DeleteObject')));
    }

    public function test_concurrent_deletion_and_restore_reservation_cannot_claim_an_artifact_being_deleted(): void
    {
        $old = $this->copies();
        $this->approve();
        $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (! app()->environment('testing') || ! str_starts_with(Illuminate\Support\Facades\DB::connection()->getDatabaseName(), 'codex_public_ids_test_')) { throw new LogicException('Disposable schema required'); }
        config(['backup-restore.automation.retention_deletion_approved' => true, 'backup-restore.automation.conditional_delete_verified' => true]);
        config(['backup-restore.automation.approved_deletion_scope' => App\Modules\BackupRestore\Models\BackupDestination::query()->firstOrFail()->only(['endpoint', 'bucket', 'prefix'])]);
        $app->instance(App\Modules\BackupRestore\Services\BackupReadiness::class, new class extends App\Modules\BackupRestore\Services\BackupReadiness { public function blockers(): array { return []; } });
        $app->instance(App\Modules\BackupRestore\Services\R2Storage::class, new class extends App\Modules\BackupRestore\Services\R2Storage {
            public function disk(App\Modules\BackupRestore\Models\BackupDestination $destination): Illuminate\Filesystem\AwsS3V3Adapter { throw new LogicException('Network prohibited'); }
        });
        try {
            app(App\Modules\BackupRestore\Services\RetentionService::class)->delete(App\Modules\AccessManagement\Models\User::query()->where('uuid', $argv[1])->firstOrFail(), $argv[2]);
            throw new LogicException('Concurrent deletion escaped');
        } catch (App\Modules\BackupRestore\Services\BackupFailure $failure) { echo $failure->getMessage(); }
        PHP;
        $observed = [];
        $this->s3->onDelete = function () use ($old, $code, &$observed): void {
            $child = new Process([PHP_BINARY, '-r', $code, $this->actor->uuid, $old->uuid], base_path());
            $child->setTimeout(30)->run();
            $observed['child_success'] = $child->isSuccessful();
            $observed['child_output'] = $child->getOutput();
            $observed['child_error'] = $child->getErrorOutput();
            foreach (['restore' => fn () => BackupRestoreRun::factory()->create(['backup_run_id' => $old->id]),
                'hold' => fn () => $old->fresh()->update(['legal_hold' => true])] as $kind => $operation) {
                try {
                    $operation();
                    $observed[$kind] = 'unexpectedly_allowed';
                } catch (BackupFailure $failure) {
                    $observed[$kind] = $failure->getMessage();
                }
            }
        };
        app(RetentionService::class)->delete($this->actor, $old->uuid);
        $this->assertTrue($observed['child_success'], $observed['child_error']);
        $this->assertSame('retention_ineligible', $observed['child_output']);
        $this->assertSame('retention_ineligible', $observed['restore']);
        $this->assertSame('retention_ineligible', $observed['hold']);
        $this->assertSame('deleted', $old->fresh()->status);
        $this->assertSame(1, count(array_filter($this->s3->commands, fn (string $command): bool => $command === 'DeleteObject')));
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
