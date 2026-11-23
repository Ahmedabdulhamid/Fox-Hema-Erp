<?php

namespace App\Modules\BackupRestore\Services;

class BackupCoverage
{
    /** @return array<string, array{status: string, blockers: list<string>}> */
    public function report(): array
    {
        $blockers = app(BackupReadiness::class)->captureBlockers();

        return [
            'capture' => ['status' => $blockers === [] ? 'configured' : 'blocked', 'blockers' => $blockers],
        ];
    }
}
