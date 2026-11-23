<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class ModuleAccess
{
    public function isActive(string $code): bool
    {
        if ($this->modulePermissionsInstalled() && RequestAuthorizationFacts::readBoundary() !== null) {
            return $this->accessFacts()['activeModules'][strtolower($code)] ?? false;
        }

        return RequestAuthorizationFacts::remember('module-active:'.$code,
            fn (): bool => Module::query()->where('code', $code)->where('is_active', true)->exists());
    }

    public function deniesPermission(string $permission): bool
    {
        if (! $this->modulePermissionsInstalled()) {
            return false;
        }

        return $this->factsDenyPermission($this->accessFacts(), $permission);
    }

    /** @param array{activeModules: array<string, bool>, permissionModules: array<string, list<string>>} $facts */
    private function factsDenyPermission(array $facts, string $permission): bool
    {
        foreach ($facts['permissionModules'][strtolower($permission)] ?? [] as $moduleCode) {
            if (! ($facts['activeModules'][$moduleCode] ?? false)) {
                return true;
            }
        }

        return false;
    }

    public function authorize(User $user, string $moduleCode, string $permission): void
    {
        abort_unless($user->is_active, 403);
        $facts = $this->modulePermissionsInstalled() ? $this->accessFacts() : null;
        $moduleIsActive = $facts !== null
            ? ($facts['activeModules'][strtolower($moduleCode)] ?? false)
            : RequestAuthorizationFacts::remember('module-active:'.$moduleCode,
                fn (): bool => Module::query()->where('code', $moduleCode)->where('is_active', true)->exists());
        abort_unless($moduleIsActive, 403);
        $mappedToModule = $facts !== null
            ? in_array(strtolower($moduleCode), $facts['permissionModules'][strtolower($permission)] ?? [], true)
            : RequestAuthorizationFacts::remember('module-permission:'.json_encode([$moduleCode, $permission], JSON_THROW_ON_ERROR),
                fn (): bool => Module::query()->where('code', $moduleCode)->whereHas('permissions', fn ($query) => $query->where('permissions.name', $permission)->where('guard_name', 'web'))->exists());
        abort_unless($mappedToModule, 403);
        abort_unless(($facts === null || ! $this->factsDenyPermission($facts, $permission))
            && ($user->isGlobalSuperAdmin() || $user->can($permission)), 403);
    }

    private function modulePermissionsInstalled(): bool
    {
        return RequestAuthorizationFacts::tableExists('modules_permissions');
    }

    /**
     * @return array{activeModules: array<string, bool>, permissionModules: array<string, list<string>>}
     */
    private function accessFacts(): array
    {
        return RequestAuthorizationFacts::moduleAccessFacts(function (): array {
            $rows = DB::table('modules')
                ->leftJoin('modules_permissions', 'modules.id', '=', 'modules_permissions.module_id')
                ->leftJoin('permissions', function (JoinClause $join): void {
                    $join->on('permissions.id', '=', 'modules_permissions.permission_id')
                        ->where('permissions.guard_name', 'web');
                })
                ->get(['modules.code', 'modules.is_active', 'permissions.name as permission_name']);
            $activeModules = [];
            $permissionModules = [];
            foreach ($rows as $row) {
                $moduleCode = strtolower((string) $row->code);
                $activeModules[$moduleCode] = (bool) $row->is_active;
                if ($row->permission_name !== null) {
                    $permissionModules[strtolower($row->permission_name)][] = $moduleCode;
                }
            }

            return ['activeModules' => $activeModules, 'permissionModules' => $permissionModules];
        });
    }
}
