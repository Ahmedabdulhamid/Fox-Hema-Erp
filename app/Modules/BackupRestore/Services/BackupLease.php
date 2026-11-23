<?php

namespace App\Modules\BackupRestore\Services;

final class BackupLease
{
    public function __construct(public readonly string $runUuid, #[\SensitiveParameter] public readonly string $owner, public readonly int $fence) {}
}
