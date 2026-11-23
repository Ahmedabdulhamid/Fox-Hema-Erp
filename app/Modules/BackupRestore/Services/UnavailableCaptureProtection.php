<?php

namespace App\Modules\BackupRestore\Services;

use Closure;

final class UnavailableCaptureProtection implements CaptureProtection
{
    public function blockers(): array
    {
        return ['capture_protection_unavailable'];
    }

    public function capture(BackupLease $lease, Closure $capture): mixed
    {
        throw new BackupFailure('capture_protection_unavailable');
    }

    public function mode(): string
    {
        throw new BackupFailure('capture_protection_unavailable');
    }

    public function assertHeld(BackupLease $lease): void
    {
        throw new BackupFailure('capture_protection_unavailable');
    }
}
