<?php

namespace Tests\Support;

use App\Modules\BackupRestore\Services\CoordinatedCaptureProtection;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Exercise the barrier on a disposable schema, never attest production coverage. */
final class VerifiedTestCaptureProtection extends CoordinatedCaptureProtection
{
    public function blockers(): array
    {
        if (! app()->environment('testing') || ! str_starts_with(DB::connection()->getDatabaseName(), 'codex_public_ids_test_')
            || ! config('backup-restore.write_admission_enabled')) {
            throw new LogicException('Capture protection fixture requires an admitted disposable database.');
        }

        return [];
    }
}
