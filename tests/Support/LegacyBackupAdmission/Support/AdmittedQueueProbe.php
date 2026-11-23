<?php

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use LogicException;

class AdmittedQueueProbe implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        if (! app()->environment('testing') || ! str_starts_with(DB::connection()->getDatabaseName(), 'codex_public_ids_test_')
            || DB::table('backup_write_operations')->count() !== 1) {
            throw new LogicException('Queue fixture requires exactly one admitted disposable operation.');
        }
        DB::table('backup_write_test_evidence')->where('id', 1)->increment('quantity');
    }
}
