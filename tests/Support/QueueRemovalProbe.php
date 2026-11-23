<?php

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use LogicException;

class QueueRemovalProbe implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        if (! app()->environment('testing') || (int) DB::connection()->getConfig('port') !== 33316
            || ! str_starts_with(DB::connection()->getDatabaseName(), 'codex_public_ids_test_')) {
            throw new LogicException('Queue fixture requires a disposable local test database.');
        }
        DB::transaction(fn () => DB::table('backup_removal_probe')->where('id', 1)->increment('amount'));
    }
}
