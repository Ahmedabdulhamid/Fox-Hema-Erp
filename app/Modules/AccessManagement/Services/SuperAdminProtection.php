<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\User;
use Illuminate\Validation\ValidationException;

class SuperAdminProtection
{
    public function ensureCanRemove(User $user): void
    {
        ModuleRegistryLock::acquire();
        if (
            $user->is_active && ! $user->trashed() && $user->isGlobalSuperAdmin()
            && ! User::query()->where('is_active', true)->whereKeyNot($user->id)->get()->contains(fn (User $other): bool => $other->isGlobalSuperAdmin())
        ) {
            throw ValidationException::withMessages(['data.is_active' => __('The last active Super Admin cannot be disabled or demoted.')]);
        }
    }
}
