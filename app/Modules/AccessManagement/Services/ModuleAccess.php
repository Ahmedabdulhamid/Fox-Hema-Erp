<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ModuleAccess
{
    public function isActive(string $code): bool
    {
        return Module::query()->where('code', $code)->where('is_active', true)->exists();
    }

    public function deniesPermission(string $permission): bool
    {
        if (! Schema::hasTable('modules_permissions')) {
            return false;
        }

        return DB::table('modules_permissions')->join('modules', 'modules.id', '=', 'modules_permissions.module_id')
            ->join('permissions', 'permissions.id', '=', 'modules_permissions.permission_id')
            ->where('permissions.guard_name', 'web')->where('permissions.name', $permission)
            ->where('modules.is_active', false)->exists();
    }

    public function authorize(User $user, string $moduleCode, string $permission): void
    {
        abort_unless($user->is_active && $this->isActive($moduleCode), 403);
        abort_unless(Module::query()->where('code', $moduleCode)->whereHas('permissions', fn ($query) => $query->where('permissions.name', $permission)->where('guard_name', 'web'))->exists(), 403);
        abort_unless(! $this->deniesPermission($permission) && ($user->isGlobalSuperAdmin() || $user->can($permission)), 403);
    }
}
