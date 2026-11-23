<?php

namespace Tests\Unit;

use App\Modules\BackupRestore\Models\BackupPolicy;
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\BackupSchedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class BackupScheduleTest extends TestCase
{
    public function test_invalid_schedule_configuration_fails_closed(): void
    {
        $this->expectException(BackupFailure::class);
        $this->expectExceptionMessage('schedule_invalid');
        (new BackupSchedule)->occurrence($this->policy(['timezone' => 'UTC', 'daily_time' => '25:90:00', 'schedule_frequency' => 'daily']), CarbonImmutable::now('UTC'));
    }

    private function policy(array $attributes): BackupPolicy
    {
        return (new BackupPolicy)->setRawAttributes($attributes);
    }

    public function test_daily_schedule_uses_explicit_policy_timezone(): void
    {
        $policy = $this->policy(['timezone' => 'Africa/Cairo', 'daily_time' => '02:00:00', 'schedule_frequency' => 'daily']);
        $at = CarbonImmutable::parse('2026-11-14 00:05:00', 'UTC');
        $this->assertSame('2026-11-14 00:00:00', (new BackupSchedule)->occurrence($policy, $at)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-15 00:00:00', (new BackupSchedule)->occurrence($policy, $at, true)->format('Y-m-d H:i:s'));
    }

    public function test_weekly_schedule_only_uses_the_configured_weekday(): void
    {
        $policy = $this->policy(['timezone' => 'UTC', 'daily_time' => '03:15:00', 'schedule_frequency' => 'weekly', 'schedule_weekday' => 0]);
        $at = CarbonImmutable::parse('2026-11-14 12:00:00', 'UTC');
        $this->assertSame('2026-11-08 03:15:00', (new BackupSchedule)->occurrence($policy, $at)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-15 03:15:00', (new BackupSchedule)->occurrence($policy, $at, true)->format('Y-m-d H:i:s'));
    }

    public function test_nonexistent_dst_time_is_skipped_without_silently_shifting_the_backup(): void
    {
        $policy = $this->policy(['timezone' => 'America/New_York', 'daily_time' => '02:30:00', 'schedule_frequency' => 'daily']);
        $at = CarbonImmutable::parse('2026-03-08 08:00:00', 'UTC');
        $this->assertSame('2026-03-07 07:30:00', (new BackupSchedule)->occurrence($policy, $at)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-09 06:30:00', (new BackupSchedule)->occurrence($policy, $at, true)->format('Y-m-d H:i:s'));
    }

    public function test_repeated_dst_time_has_one_first_occurrence_per_civil_day(): void
    {
        $policy = $this->policy(['timezone' => 'America/New_York', 'daily_time' => '01:30:00', 'schedule_frequency' => 'daily']);
        $at = CarbonImmutable::parse('2026-11-01 06:10:00', 'UTC');
        $this->assertSame('2026-11-01 05:30:00', (new BackupSchedule)->occurrence($policy, $at)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 06:30:00', (new BackupSchedule)->occurrence($policy, $at, true)->format('Y-m-d H:i:s'));
    }
}
