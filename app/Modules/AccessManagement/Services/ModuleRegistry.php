<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\Products\Services\ProductPermissions;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Taxes\Services\DefinitionPermissions;
use App\Modules\Treasury\Services\TreasuryPermissions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class ModuleRegistry
{
    public const MODULES = [
        'access_management' => 'AccessManagement', 'products' => 'Products',
        'inventory' => 'Inventory', 'inventory_costing' => 'Inventory Costing',
        'purchasing' => 'Purchasing', 'taxes' => 'Taxes', 'discounts' => 'Discounts',
        'sales' => 'Sales', 'pos' => 'POS', 'stock_transfers' => 'Stock Transfers', 'payments' => 'Payments', 'treasury' => 'Treasury',
    ];

    public function register(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $originalScope = $registrar->getPermissionsTeamId();
        try {
            $registrar->setPermissionsTeamId(null);
            DB::transaction(function (): void {
                foreach (app(ModuleDiscovery::class)->modules() as $code => $directory) {
                    $name = self::MODULES[$code] ?? $directory;
                    Module::query()->firstOrCreate(['code' => $code], [
                        'name' => $name,
                        'description' => match ($code) {
                            'access_management' => 'Users, roles, locations, security, modules and permissions.',
                            'products' => 'Shared product catalog, variants, images and product tax/discount assignments.',
                            'taxes' => 'Tax definitions and product tax assignments.',
                            'discounts' => 'Discount definitions and product discount assignments.',
                            'inventory' => 'Read-only stock balances and movements with atomic inventory posting.',
                            'inventory_costing' => 'Category settings and receipt-side FIFO, AVCO and Standard Price valuation. Issue valuation is not implemented.',
                            'purchasing' => 'Suppliers, purchase orders, goods receipts and receipt-matched vendor bills.',
                            'sales' => 'Branch-scoped sales orders with atomic confirmation, reservations and cancellation.',
                            default => 'Planned: database design exists; business pages and operations are not implemented.',
                        },
                        'icon' => 'heroicon-o-squares-2x2',
                        'sort_order' => (array_search($code, array_keys(self::MODULES), true) ?: 0) * 10,
                        'is_active' => $code === 'access_management',
                    ]);
                }
                ModuleRegistryLock::acquire();
                foreach ($this->implementedPermissions() as $name) {
                    Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                }
                $permissions = Permission::query()->where('guard_name', 'web')->get();
                foreach (Module::query()->get() as $module) {
                    $ids = $permissions->filter(function (Permission $permission) use ($module): bool {
                        if ($module->code === 'access_management') {
                            return in_array(str($permission->name)->before('.')->toString(), [
                                'users', 'roles', 'permissions', 'locations', 'sessions', 'auth_events', 'modules',
                            ], true);
                        }

                        return str_starts_with($permission->name, $module->code.'.');
                    })->pluck('id');
                    $module->permissions()->syncWithoutDetaching($ids);
                }
                $superAdmin = Role::query()->firstOrCreate([
                    'location_id' => null, 'name' => 'super_admin', 'guard_name' => 'web',
                ]);
                $superAdmin->permissions()->syncWithoutDetaching(Permission::query()->where('guard_name', 'web')->pluck('id'));
            });
        } finally {
            $registrar->setPermissionsTeamId($originalScope);
            $registrar->forgetCachedPermissions();
        }
    }

    /** @return list<string> */
    public function implementedPermissions(): array
    {
        $modules = app(ModuleDiscovery::class)->modules();
        $permissions = [];
        if (isset($modules['access_management'])) {
            $permissions = AccessManagementSeeder::PERMISSIONS;
        }
        if (isset($modules['products'])) {
            $permissions = [...$permissions, ...ProductPermissions::all()];
        }
        if (isset($modules['inventory'])) {
            $permissions = [...$permissions, ...InventoryPermissions::all()];
        }
        if (isset($modules['purchasing'])) {
            $permissions = [...$permissions, ...PurchasingPermissions::all()];
        }
        if (isset($modules['payments'])) {
            $permissions = [...$permissions, ...PaymentsPermissions::all()];
        }
        if (isset($modules['treasury'])) {
            $permissions = [...$permissions, ...TreasuryPermissions::all()];
        }
        foreach (DefinitionPermissions::all() as $permission) {
            if (isset($modules[str($permission)->before('.')->toString()])) {
                $permissions[] = $permission;
            }
        }

        if (isset($modules['sales'])) {
            $permissions = [...$permissions, ...SalesPermissions::all()];
        }

        return array_values(array_unique($permissions));
    }
}
