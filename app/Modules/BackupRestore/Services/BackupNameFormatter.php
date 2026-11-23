<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\BackupRestore\Models\BackupRun;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Lang;

class BackupNameFormatter
{
    /**
     * @param  iterable<BackupRun>  $backups
     * @return array<string, string>
     */
    public function labels(iterable $backups): array
    {
        $runs = collect($backups)
            ->filter(fn (mixed $run): bool => $run instanceof BackupRun && $run->created_at !== null)
            ->unique('uuid')
            ->values();

        if ($runs->isEmpty()) {
            return [];
        }

        $groups = $runs->groupBy(fn (BackupRun $run): string => $this->minuteKey($run));
        $matches = BackupRun::query()
            ->select(['id', 'uuid', 'trigger', 'created_at'])
            ->where(function (Builder $query) use ($groups): void {
                foreach ($groups as $group) {
                    /** @var BackupRun $run */
                    $run = $group->first();
                    $minute = $this->minuteStart($run->created_at);

                    $query->orWhere(fn (Builder $query) => $query
                        ->where('trigger', $run->trigger)
                        ->where('created_at', '>=', $minute)
                        ->where('created_at', '<', $minute->copy()->addMinute()));
                }
            })
            ->orderBy('id')
            ->get();

        $matchesByMinute = $matches->groupBy(fn (BackupRun $run): string => $this->minuteKey($run));
        $labels = [];

        foreach ($runs as $run) {
            $label = __('backup_restore.backup_name', [
                'type' => __('backup_restore.backup_types.'.$this->backupType($run->trigger)),
                'date' => $this->dateTime($run->created_at),
            ]);
            $sameMinute = $matchesByMinute->get($this->minuteKey($run), collect());

            if ($sameMinute->count() > 1) {
                $number = $sameMinute->search(fn (BackupRun $candidate): bool => $candidate->uuid === $run->uuid) + 1;
                $label = __('backup_restore.backup_name_suffix', ['name' => $label, 'number' => $number]);
            }

            $labels[$run->uuid] = $label;
        }

        return $labels;
    }

    public function dateTime(?CarbonInterface $dateTime): string
    {
        if ($dateTime === null) {
            return '—';
        }

        $format = app()->getLocale() === 'ar' ? 'j F Y، h:i A' : 'j M Y, h:i A';

        return $dateTime->copy()
            ->timezone((string) config('app.timezone'))
            ->locale(app()->getLocale())
            ->translatedFormat($format);
    }

    public function fileSize(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }

        $size = (float) $bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return number_format($size, $unit === 0 ? 0 : 1).' '.$units[$unit];
    }

    public function failureMessage(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $key = 'backup_restore.errors.'.$code;

        return Lang::has($key) ? __($key) : __('backup_restore.errors.backup_failed');
    }

    private function minuteKey(BackupRun $run): string
    {
        return $run->trigger.'|'.$this->minuteStart($run->created_at)->format('Y-m-d H:i');
    }

    private function minuteStart(CarbonInterface $dateTime): CarbonInterface
    {
        return $dateTime->copy()
            ->timezone((string) config('app.timezone'))
            ->startOfMinute();
    }

    private function backupType(string $trigger): string
    {
        return in_array($trigger, ['manual', 'scheduled'], true) ? $trigger : 'other';
    }
}
