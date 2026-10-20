<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class SuperAdminProvisioner
{
    public function provision(string $email, bool $assignExisting, ?string $name = null, ?string $password = null): User
    {
        Validator::make(['email' => $email], ['email' => 'required|email|max:255'])->validate();
        $registrar = app(PermissionRegistrar::class);
        $originalScope = $registrar->getPermissionsTeamId();
        try {
            $registrar->setPermissionsTeamId(null);

            return DB::transaction(function () use ($email, $assignExisting, $name, $password): User {
                ModuleRegistryLock::acquire();
                $user = User::withTrashed()->where('email', $email)->lockForUpdate()->first();
                if ($user !== null) {
                    if (! $assignExisting) {
                        throw ValidationException::withMessages(['email' => 'Existing users require --assign-existing.']);
                    }
                    if ($user->trashed() || ! $user->is_active) {
                        throw ValidationException::withMessages(['email' => 'Disabled or deleted users must be reviewed and restored separately.']);
                    }
                } else {
                    if ($assignExisting) {
                        throw ValidationException::withMessages(['email' => 'The target user does not exist.']);
                    }
                    Validator::make(['name' => $name, 'password' => $password], [
                        'name' => 'required|string|max:255', 'password' => 'required|string|min:12|max:1024',
                    ])->validate();
                    $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password,
                        'is_active' => true, 'must_change_password' => true]);
                }
                $role = Role::query()->whereNull('location_id')->where('name', 'super_admin')->where('guard_name', 'web')->first();
                if ($role === null) {
                    throw ValidationException::withMessages(['email' => 'Run the AccessManagement seeder before provisioning.']);
                }
                if (! $user->isGlobalSuperAdmin()) {
                    $user->unsetRelation('roles')->unsetRelation('permissions');
                    $user->assignRole($role);
                    app(AuditLogger::class)->record('super_admin_provisioned', $user);
                }

                return $user;
            });
        } finally {
            $registrar->setPermissionsTeamId($originalScope);
            $registrar->forgetCachedPermissions();
        }
    }
}
