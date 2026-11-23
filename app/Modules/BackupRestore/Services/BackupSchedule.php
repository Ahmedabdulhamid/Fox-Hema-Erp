<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\BackupRestore\Models\BackupPolicy;
use Carbon\CarbonImmutable;
use DateTimeZone;

class BackupSchedule
{
    /** One occurrence per civil date; skip missing DST times and choose the first repeated time. */
    public function occurrence(BackupPolicy $policy, CarbonImmutable $at, bool $next = false): CarbonImmutable
    {
        if (! in_array($policy->timezone, DateTimeZone::listIdentifiers(), true)
            || ! in_array($policy->schedule_frequency, ['daily', 'weekly'], true)
            || ! preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::00)?\z/D', $policy->daily_time ?? '')
            || ($policy->schedule_frequency === 'weekly' && ! in_array((int) $policy->schedule_weekday, range(0, 6), true))) {
            throw new BackupFailure('schedule_invalid');
        }
        $zone = new DateTimeZone($policy->timezone);
        $local = $at->setTimezone($zone);
        $matches = [];
        for ($offset = -8; $offset <= 8; $offset++) {
            $day = $local->startOfDay()->addDays($offset);
            if ($policy->schedule_frequency === 'weekly' && $day->dayOfWeek !== (int) $policy->schedule_weekday) {
                continue;
            }
            $civil = $day->format('Y-m-d').' '.substr($policy->daily_time, 0, 5).':00';
            $naive = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $civil, 'UTC');
            $offsets = array_unique(array_column($zone->getTransitions($naive->timestamp - 86400, $naive->timestamp + 86400), 'offset'));
            $possibilities = [];
            foreach ($offsets as $seconds) {
                $candidate = $naive->subSeconds($seconds);
                if ($candidate->setTimezone($zone)->format('Y-m-d H:i:s') === $civil) {
                    $possibilities[] = $candidate;
                }
            }
            usort($possibilities, fn (CarbonImmutable $left, CarbonImmutable $right): int => $left->timestamp <=> $right->timestamp);
            $candidate = $possibilities[0] ?? null;
            if ($candidate && ($next ? $candidate->gt($at) : $candidate->lte($at))) {
                $matches[] = $candidate;
            }
        }
        usort($matches, fn (CarbonImmutable $left, CarbonImmutable $right): int => $left->timestamp <=> $right->timestamp);
        if ($matches === []) {
            throw new BackupFailure('schedule_invalid');
        }

        return $next ? $matches[0] : $matches[array_key_last($matches)];
    }
}
