<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\POS\Services\PosPermissions;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Treasury\Services\TreasuryPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Spatie\Permission\PermissionRegistrar;

class LoadTestingAccessBootstrapper
{
    public const USER_EMAIL = 'loadtest-admin@example.test';

    public const ROLE_NAME = 'load_testing_operator';

    public const CASHIER_ROLE_NAME = 'load_testing_cashier';

    public const PERMISSIONS = [
        SalesPermissions::VIEW,
        SalesPermissions::DELIVERIES_VIEW,
        PosPermissions::VIEW,
        PosPermissions::CHECKOUT,
        PosPermissions::SHIFTS_OPEN,
        PosPermissions::SHIFTS_CLOSE,
    ];

    public const CASHIER_PERMISSIONS = [
        SalesPermissions::VIEW,
        SalesPermissions::CREATE,
        SalesPermissions::CONFIRM,
        SalesPermissions::DELIVERIES_VIEW,
        SalesPermissions::DELIVERIES_CREATE,
        SalesPermissions::DELIVERIES_POST,
        SalesPermissions::INVOICES_VIEW,
        SalesPermissions::INVOICES_CREATE,
        SalesPermissions::INVOICES_POST,
        PosPermissions::VIEW,
        PosPermissions::CHECKOUT,
        PosPermissions::SHIFTS_OPEN,
        PosPermissions::SHIFTS_CLOSE,
        InventoryPermissions::POST,
        PaymentsPermissions::VIEW,
        PaymentsPermissions::CREATE,
        PaymentsPermissions::POST,
        PaymentsPermissions::ALLOCATE,
        TreasuryPermissions::VIEW,
        TreasuryPermissions::CREATE,
        TreasuryPermissions::POST,
    ];

    /** @var list<string> */
    public const REQUIRED_MODULES = [
        'sales',
        'pos',
        'inventory',
        'inventory_costing',
        'payments',
        'treasury',
    ];

    /** @var list<array{slug: string, name: string, cashier_email: string, cashier_name: string}> */
    public const BRANCHES = [
        [
            'slug' => 'load-testing-branch',
            'name' => 'Load Testing Branch',
            'cashier_email' => 'loadtest-cashier-1@example.test',
            'cashier_name' => 'Load Testing Cashier 1',
        ],
        [
            'slug' => 'load-testing-branch-2',
            'name' => 'Load Testing Branch 2',
            'cashier_email' => 'loadtest-cashier-2@example.test',
            'cashier_name' => 'Load Testing Cashier 2',
        ],
        [
            'slug' => 'load-testing-branch-3',
            'name' => 'Load Testing Branch 3',
            'cashier_email' => 'loadtest-cashier-3@example.test',
            'cashier_name' => 'Load Testing Cashier 3',
        ],
    ];

    public function __construct(private readonly ModuleRegistry $registry) {}

    /** @return list<string> */
    public static function cashierEmails(): array
    {
        return array_column(self::BRANCHES, 'cashier_email');
    }

    /**
     * @param  array<string, string>  $cashierPasswords
     * @return array{
     *     location_id: int,
     *     role_id: int,
     *     user_id: int,
     *     branch_ids: list<int>,
     *     cashier_role_ids: list<int>,
     *     cashier_ids: list<int>
     * }
     */
    public function bootstrap(string $password, array $cashierPasswords): array
    {
        if (strlen($password) < 32) {
            throw new LogicException('The load-testing administrator credential is invalid.');
        }

        $this->validateCashierPasswords($password, $cashierPasswords);

        try {
            $result = DB::transaction(function () use ($password, $cashierPasswords): array {
                ModuleRegistryLock::acquire();
                $this->assertNoUnexpectedLocations();
                $this->registry->register();

                $locations = $this->ensureBranches();
                $this->activateRequiredModules();
                $this->assertRequiredPermissionsExist();

                $adminRole = $this->ensureRole($locations[0], self::ROLE_NAME, self::PERMISSIONS);
                $admin = $this->ensureUser(
                    self::USER_EMAIL,
                    'Load Testing Administrator',
                    $password,
                    $locations[0],
                    $adminRole,
                );

                $cashierIds = [];
                $cashierRoleIds = [];
                foreach (self::BRANCHES as $index => $branch) {
                    $location = $locations[$index];
                    $role = $this->ensureRole($location, self::CASHIER_ROLE_NAME, self::CASHIER_PERMISSIONS);
                    $cashier = $this->ensureUser(
                        $branch['cashier_email'],
                        $branch['cashier_name'],
                        $cashierPasswords[$branch['cashier_email']],
                        $location,
                        $role,
                    );
                    $cashierIds[] = (int) $cashier->getKey();
                    $cashierRoleIds[] = (int) $role->getKey();
                }

                return [
                    'location_id' => (int) $locations[0]->getKey(),
                    'role_id' => (int) $adminRole->getKey(),
                    'user_id' => (int) $admin->getKey(),
                    'branch_ids' => array_map(fn (Location $location): int => (int) $location->getKey(), $locations),
                    'cashier_role_ids' => $cashierRoleIds,
                    'cashier_ids' => $cashierIds,
                ];
            }, 3);
        } finally {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            RequestAuthorizationFacts::forget();
        }

        return $result;
    }

    private function assertNoUnexpectedLocations(): void
    {
        $reservedSlugs = array_column(self::BRANCHES, 'slug');
        $unexpectedLocation = Location::query()
            ->where(function ($query) use ($reservedSlugs): void {
                $query->whereNull('slug')->orWhereNotIn('slug', $reservedSlugs);
            })
            ->lockForUpdate()
            ->first();

        if ($unexpectedLocation !== null) {
            throw new LogicException('Unexpected locations exist in the isolated load-testing database.');
        }
    }

    /** @return list<Location> */
    private function ensureBranches(): array
    {
        $locations = [];
        foreach (self::BRANCHES as $branch) {
            $location = Location::query()->where('slug', $branch['slug'])->lockForUpdate()->first();
            if ($location === null) {
                $location = Location::query()->create([
                    'name' => $branch['name'],
                    'type' => 'branch',
                    'is_active' => true,
                ]);
            }

            if ($location->slug !== $branch['slug'] || $location->name !== $branch['name']
                || $location->type !== 'branch' || ! $location->is_active) {
                throw new LogicException('A reserved load-testing branch conflicts with its expected identity.');
            }

            $locations[] = $location;
        }

        return $locations;
    }

    private function activateRequiredModules(): void
    {
        $modules = Module::query()->whereIn('code', self::REQUIRED_MODULES)->get()->keyBy('code');
        if ($modules->count() !== count(self::REQUIRED_MODULES)) {
            throw new LogicException('Required POS checkout modules were not registered.');
        }

        Module::query()->whereIn('code', self::REQUIRED_MODULES)->update(['is_active' => true]);
    }

    private function assertRequiredPermissionsExist(): void
    {
        $required = array_values(array_unique([...self::PERMISSIONS, ...self::CASHIER_PERMISSIONS]));
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $required)
            ->pluck('name')
            ->all();

        if (count($permissions) !== count($required)) {
            throw new LogicException('Required POS checkout permissions were not registered.');
        }
    }

    /** @param list<string> $permissionNames */
    private function ensureRole(Location $location, string $name, array $permissionNames): Role
    {
        if (Role::query()->whereNull('location_id')->where('name', $name)->where('guard_name', 'web')->exists()) {
            throw new LogicException('A reserved load-testing role conflicts with a global role.');
        }

        $role = Role::query()
            ->where('location_id', $location->getKey())
            ->where('name', $name)
            ->where('guard_name', 'web')
            ->lockForUpdate()
            ->first();

        if ($role === null) {
            $role = Role::query()->create([
                'location_id' => $location->getKey(),
                'name' => $name,
                'guard_name' => 'web',
            ]);
            $permissions = Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $permissionNames)
                ->get();
            $role->syncPermissions($permissions);
        } elseif ($this->sorted($role->permissions()->where('guard_name', 'web')->pluck('name')->all())
            !== $this->sorted($permissionNames)) {
            throw new LogicException('A reserved load-testing role has conflicting permissions.');
        }

        return $role;
    }

    private function ensureUser(
        string $email,
        string $name,
        string $password,
        Location $location,
        Role $role,
    ): User {
        $user = User::withTrashed()->where('email', $email)->lockForUpdate()->first();
        if ($user?->trashed()) {
            throw new LogicException('A reserved load-testing user is soft deleted.');
        }

        if ($user === null) {
            $this->assertRoleHasNoAssignments($role);
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'is_active' => true,
                'must_change_password' => false,
                'password_changed_at' => now(),
            ]);
            $user->locations()->attach($location->getKey());
            $this->assignRole($user, $role, $location);

            return $user;
        }

        if ($user->name !== $name || ! $user->is_active || $user->isGlobalSuperAdmin()
            || ! Hash::check($password, (string) $user->password)) {
            throw new LogicException('A reserved load-testing user conflicts with its expected identity or credential.');
        }

        $locationIds = $user->locations()->orderBy('locations.id')->pluck('locations.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $assignments = DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->get(['role_id', 'location_id']);
        $roleAssignments = DB::table(config('permission.table_names.model_has_roles'))
            ->where('role_id', $role->getKey())
            ->get(['model_type', 'model_id', 'location_id']);
        $directPermissions = DB::table(config('permission.table_names.model_has_permissions'))
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->exists();

        if ($locationIds !== [(int) $location->getKey()] || $assignments->count() !== 1
            || (int) $assignments->first()->role_id !== (int) $role->getKey()
            || (int) $assignments->first()->location_id !== (int) $location->getKey()
            || $roleAssignments->count() !== 1
            || $roleAssignments->first()->model_type !== $user->getMorphClass()
            || (int) $roleAssignments->first()->model_id !== (int) $user->getKey()
            || (int) $roleAssignments->first()->location_id !== (int) $location->getKey()
            || $directPermissions) {
            throw new LogicException('A reserved load-testing user has conflicting branch or role assignments.');
        }

        return $user;
    }

    private function assertRoleHasNoAssignments(Role $role): void
    {
        if (DB::table(config('permission.table_names.model_has_roles'))->where('role_id', $role->getKey())->exists()) {
            throw new LogicException('A reserved load-testing role is already assigned to another user.');
        }
    }

    private function assignRole(User $user, Role $role, Location $location): void
    {
        $registrar = app(PermissionRegistrar::class);
        $originalScope = $registrar->getPermissionsTeamId();
        try {
            $registrar->setPermissionsTeamId($location->getKey());
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->assignRole($role);
        } finally {
            $registrar->setPermissionsTeamId($originalScope);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /** @param array<string, string> $cashierPasswords */
    private function validateCashierPasswords(string $administratorPassword, array $cashierPasswords): void
    {
        if (array_keys($cashierPasswords) !== self::cashierEmails()) {
            throw new LogicException('The isolated cashier credentials do not match the reserved accounts.');
        }

        foreach ($cashierPasswords as $password) {
            if (! is_string($password) || strlen($password) < 32) {
                throw new LogicException('An isolated cashier credential is invalid.');
            }
        }

        if (count(array_unique(array_values($cashierPasswords))) !== count($cashierPasswords)) {
            throw new LogicException('Each isolated cashier requires a distinct credential.');
        }

        if (in_array($administratorPassword, array_values($cashierPasswords), true)) {
            throw new LogicException('Cashier credentials must be distinct from the administrator credential.');
        }
    }

    /** @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
