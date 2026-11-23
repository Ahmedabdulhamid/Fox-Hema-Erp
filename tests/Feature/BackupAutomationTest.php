<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\BackupRestore\Filament\Pages\BackupHistory;
use App\Modules\BackupRestore\Filament\Pages\BackupSettings;
use App\Modules\BackupRestore\Jobs\CreateFullBackup;
use App\Modules\BackupRestore\Models\BackupDestination;
use App\Modules\BackupRestore\Models\BackupPolicy;
use App\Modules\BackupRestore\Models\BackupRun;
use App\Modules\BackupRestore\Services\BackupAlerts;
use App\Modules\BackupRestore\Services\BackupCoordinator;
use App\Modules\BackupRestore\Services\BackupMonitoring;
use App\Modules\BackupRestore\Services\BackupReadiness;
use App\Modules\BackupRestore\Services\BackupStatusNotification;
use App\Modules\BackupRestore\Services\ScheduledBackups;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class BackupAutomationTest extends TestCase
{
    use UsesIsolatedDatabase;

    private User $actor;

    private BackupPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:05:00', 'UTC'));
        app(ModuleRegistry::class)->register();
        Module::query()->where('code', 'backup_restore')->update(['is_active' => true]);
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->update(['must_change_password' => false]);
        $destination = BackupDestination::factory()->create(['probe_status' => 'passed']);
        $this->policy = BackupPolicy::factory()->create(['destination_id' => $destination->id, 'timezone' => 'UTC', 'daily_time' => '12:00:00',
            'schedule_requested' => true, 'notification_recipients' => ['owner@example.test']]);
        Queue::fake();
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function ready(): void
    {
        config(['backup-restore.automation.scheduling_approved' => true, 'backup-restore.automation.controller_enabled' => true]);
        $readiness = Mockery::mock(BackupReadiness::class)->makePartial();
        $readiness->shouldReceive('blockers')->andReturn([]);
        app()->instance(BackupReadiness::class, $readiness);
    }

    public function test_disabled_controller_and_disabled_policy_do_not_dispatch(): void
    {
        $this->artisan('backups:automation')->assertSuccessful();
        $this->policy->update(['schedule_requested' => false]);
        $this->assertSame('disabled', app(ScheduledBackups::class)->tick());
        $this->assertDatabaseCount('backup_schedule_occurrences', 0);
        Queue::assertNothingPushed();
    }

    public function test_scheduler_registers_the_gated_automation_controller_every_minute(): void
    {
        config(['backup-restore.automation.controller_enabled' => true]);
        $events = collect(app(Schedule::class)->events())->filter(
            static fn ($event): bool => str_contains((string) $event->command, 'backups:automation'),
        );

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
    }

    public function test_flags_cannot_bypass_infrastructure_readiness(): void
    {
        config(['backup-restore.automation.scheduling_approved' => true, 'backup-restore.automation.controller_enabled' => true]);
        $readiness = Mockery::mock(BackupReadiness::class)->makePartial();
        $readiness->shouldReceive('blockers')->andReturn(['capture_protection_unavailable']);
        app()->instance(BackupReadiness::class, $readiness);

        $this->assertSame('blocked', app(ScheduledBackups::class)->tick());
        $this->assertDatabaseCount('backup_runs', 0);
        $this->assertDatabaseHas('backup_schedule_occurrences', ['status' => 'blocked', 'failure_code' => 'capture_protection_unavailable']);
        Queue::assertNothingPushed();
    }

    public function test_daily_dispatch_and_scheduler_restart_are_idempotent(): void
    {
        $this->ready();
        $this->assertSame('dispatched', app(ScheduledBackups::class)->tick());
        $this->assertSame('dispatched', (new ScheduledBackups)->tick());
        Queue::assertPushed(CreateFullBackup::class, 1);
        $this->assertDatabaseCount('backup_runs', 1);
        $this->assertDatabaseHas('backup_runs', ['trigger' => 'scheduled', 'requested_by' => null, 'status' => 'queued']);
    }

    public function test_weekly_dispatch_uses_the_same_coordinator_and_does_not_backfill_a_missed_week(): void
    {
        $this->ready();
        $this->policy->update(['schedule_frequency' => 'weekly', 'schedule_weekday' => 6]);
        $this->assertSame('dispatched', app(ScheduledBackups::class)->tick());
        Queue::assertPushed(CreateFullBackup::class, 1);
        $this->assertSame('missed', app(ScheduledBackups::class)->tick(CarbonImmutable::parse('2026-11-21 15:00:00', 'UTC')));
        $this->assertDatabaseHas('backup_schedule_occurrences', ['civil_date' => '2026-11-21', 'status' => 'missed']);
        $this->assertDatabaseCount('backup_runs', 1);
    }

    public function test_schedule_enabled_after_an_occurrence_waits_for_the_next_occurrence(): void
    {
        $this->ready();
        $this->policy->update(['schedule_requested_at' => now()]);
        $this->assertSame('not_due', app(ScheduledBackups::class)->tick());
        Queue::assertNothingPushed();
    }

    public function test_manual_and_scheduled_requests_share_overlap_protection(): void
    {
        $this->ready();
        app(BackupCoordinator::class)->request($this->actor, (string) Str::uuid());
        $this->assertSame('blocked', app(ScheduledBackups::class)->tick());
        $this->assertDatabaseHas('backup_schedule_occurrences', ['failure_code' => 'lease_busy']);
        $this->assertDatabaseCount('backup_runs', 1);
    }

    public function test_dispatch_failure_is_durable_and_cannot_be_misreported_as_success(): void
    {
        $this->ready();
        Bus::partialMock()->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('fixture-secret'));
        $this->assertSame('dispatch_failed', app(ScheduledBackups::class)->tick());
        $this->assertSame('dispatch_failed', app(ScheduledBackups::class)->tick());
        $this->assertDatabaseHas('backup_runs', ['status' => 'failed', 'failure_code' => 'queue_dispatch_failed']);
        $this->assertDatabaseHas('backup_schedule_occurrences', ['status' => 'dispatch_failed']);
    }

    public function test_settings_store_requested_state_without_activating_execution_and_validate_conflicts(): void
    {
        $this->actingAs($this->actor);
        Livewire::test(BackupSettings::class)->set('settings.schedule_requested', true)->set('settings.retention_requested', true)
            ->set('settings.schedule_frequency', 'weekly')->set('settings.schedule_weekday', 1)->call('save')->assertHasNoErrors()
            ->assertSee('Disabled / blocked');
        $this->assertTrue($this->policy->fresh()->schedule_requested);
        $this->assertFalse($this->policy->fresh()->enabled);
        Livewire::test(BackupSettings::class)->set('settings.maximum_backups', 1)->set('settings.minimum_verified', 3)
            ->call('save')->assertHasErrors(['maximum_backups']);
        Queue::assertNothingPushed();
    }

    public function test_unauthorized_settings_changes_and_bilingual_monitoring(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(BackupSettings::class)->assertForbidden();
        $this->actingAs($this->actor);
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(BackupHistory::class)->assertSee(__('backup_restore.automation.monitoring'))
                ->assertSeeHtml('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"');
        }
        $this->assertSame(array_keys(__('backup_restore.errors', [], 'en')), array_keys(__('backup_restore.errors', [], 'ar')));
    }

    public function test_notification_deduplication_delivery_gate_and_safe_payload(): void
    {
        $alerts = app(BackupAlerts::class);
        $alerts->record('storage_failed', null, 'fixture-secret');
        $alerts->record('storage_failed', null, 'fixture-secret');
        $this->assertDatabaseCount('backup_alerts', 1);
        $this->assertDatabaseHas('backup_alerts', ['occurrences' => 2, 'failure_code' => null]);
        $this->assertSame(0, $alerts->deliver());
        Notification::assertNothingSent();
        config(['backup-restore.automation.notification_delivery_approved' => true]);
        $this->assertSame(1, $alerts->deliver());
        $this->assertSame(0, $alerts->deliver());
        Notification::assertSentOnDemand(BackupStatusNotification::class);
        $message = (new BackupStatusNotification('storage_failed', null, 'fixture-secret'))->toMail(new \stdClass);
        $this->assertStringNotContainsString('fixture-secret', json_encode($message->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_monitoring_records_consecutive_storage_verification_and_readiness_failures(): void
    {
        $this->policy->destination()->firstOrFail()->update(['probe_status' => 'failed']);
        foreach (['upload_failed', 'checksum_mismatch', 'backup_failed'] as $code) {
            BackupRun::factory()->create(['status' => 'failed', 'failure_code' => $code, 'completed_at' => now()]);
        }
        app(BackupMonitoring::class)->inspect();
        foreach (['consecutive_failures', 'storage_failed', 'verification_failed', 'readiness_unavailable'] as $event) {
            $this->assertDatabaseHas('backup_alerts', ['event' => $event]);
        }
        $this->assertSame(3, app(BackupMonitoring::class)->snapshot()['consecutive_failures']);
        $this->assertDatabaseHas('backup_alerts', ['event' => 'storage_failed', 'failure_code' => 'r2_not_configured']);
        $this->assertDatabaseCount('pos_transactions', 0);
    }

    public function test_lost_scheduler_dispatch_is_reported_without_reenqueuing_a_duplicate(): void
    {
        BackupRun::factory()->create(['trigger' => 'scheduled', 'status' => 'queued', 'created_at' => now()->subHour()]);
        app(BackupMonitoring::class)->inspect();
        $this->assertDatabaseHas('backup_alerts', ['event' => 'schedule_missed', 'failure_code' => 'queue_dispatch_failed']);
        $this->assertDatabaseCount('backup_runs', 1);
        Queue::assertNothingPushed();
    }

    public function test_notification_rate_limit_and_uncertain_delivery_prevent_repeated_mail(): void
    {
        config(['backup-restore.automation.notification_delivery_approved' => true]);
        for ($index = 0; $index < 12; $index++) {
            app(BackupAlerts::class)->record('backup_verified', (string) Str::uuid());
        }
        $this->assertSame(10, app(BackupAlerts::class)->deliver());
        $this->assertSame(0, app(BackupAlerts::class)->deliver());
        $this->assertDatabaseCount('backup_alert_deliveries', 10);
        $this->travel(2)->hours();
        Notification::shouldReceive('route')->andThrow(new \RuntimeException('fixture-mail-secret'));
        $this->assertSame(0, app(BackupAlerts::class)->deliver());
        $this->assertDatabaseHas('backup_alert_deliveries', ['status' => 'uncertain']);
        $this->assertSame(0, app(BackupAlerts::class)->deliver());
    }

    public function test_duplicate_scheduler_processes_enqueue_one_occurrence(): void
    {
        $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (! app()->environment('testing') || ! str_starts_with(Illuminate\Support\Facades\DB::connection()->getDatabaseName(), 'codex_public_ids_test_')) {
            throw new LogicException('Disposable schema required');
        }
        config(['backup-restore.automation.scheduling_approved' => true, 'backup-restore.automation.controller_enabled' => true]);
        $app->instance(App\Modules\BackupRestore\Services\BackupReadiness::class, new class extends App\Modules\BackupRestore\Services\BackupReadiness {
            public function blockers(): array { return []; }
        });
        echo app(App\Modules\BackupRestore\Services\ScheduledBackups::class)->tick(Carbon\CarbonImmutable::parse('2026-11-14 12:05:00', 'UTC'));
        PHP;
        $children = [new Process([PHP_BINARY, '-r', $code], base_path()), new Process([PHP_BINARY, '-r', $code], base_path())];
        try {
            foreach ($children as $child) {
                $child->setTimeout(30)->start();
            }
            foreach ($children as $child) {
                $child->wait();
                $this->assertTrue($child->isSuccessful(), $child->getErrorOutput());
                $this->assertSame('dispatched', $child->getOutput());
            }
            $this->assertDatabaseCount('backup_runs', 1);
            $this->assertDatabaseCount('jobs', 1);
        } finally {
            foreach ($children as $child) {
                if ($child->isRunning()) {
                    $child->stop(0);
                }
            }
        }
    }
}
