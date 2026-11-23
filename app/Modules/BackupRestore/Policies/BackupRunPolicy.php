<?php

namespace App\Modules\BackupRestore\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\BackupRestore\Models\BackupRun;
use App\Modules\BackupRestore\Services\BackupAccess;
use App\Modules\BackupRestore\Services\BackupPermissions;

class BackupRunPolicy
{
    public function viewAny(User $user): bool
    {
        return app(BackupAccess::class)->allows($user, BackupPermissions::VIEW);
    }

    public function view(User $user, BackupRun $run): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, BackupRun $run): bool
    {
        return false;
    }

    public function delete(User $user, BackupRun $run): bool
    {
        return false;
    }

    public function restore(User $user, BackupRun $run): bool
    {
        return false;
    }

    public function forceDelete(User $user, BackupRun $run): bool
    {
        return false;
    }
}
