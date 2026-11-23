<?php

namespace App\Modules\BackupRestore\Services;

use Closure;

interface CaptureProtection
{
    /** @return list<string> */
    public function blockers(): array;

    public function mode(): string;

    public function capture(BackupLease $lease, Closure $capture): mixed;

    public function assertHeld(BackupLease $lease): void;
}
