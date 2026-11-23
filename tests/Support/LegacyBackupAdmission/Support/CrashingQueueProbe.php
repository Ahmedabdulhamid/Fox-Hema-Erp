<?php

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class CrashingQueueProbe implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $phase) {}

    public function handle(): void
    {
        if (! app()->environment('testing') || (int) DB::connection()->getConfig('port') !== 33316
            || ! str_starts_with(DB::connection()->getDatabaseName(), 'codex_public_ids_test_')
            || DB::table('backup_write_operations')->count() !== 1) {
            throw new \LogicException('Crash fixture requires its admitted disposable worker.');
        }
        if ($this->phase === 'open_transaction') {
            DB::beginTransaction();
            DB::table('backup_write_test_evidence')->increment('quantity');
        } elseif ($this->phase === 'after_commit') {
            DB::transaction(fn () => DB::table('backup_write_test_evidence')->increment('quantity'));
        } elseif ($this->phase !== 'after_reservation') {
            throw new \LogicException('Unknown crash fixture phase.');
        }
        exit(19);
    }
}
