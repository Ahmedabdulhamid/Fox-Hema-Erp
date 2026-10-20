<?php

namespace App\Modules\AccessManagement\Database\Seeders;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use Illuminate\Database\Seeder;

class AccessManagementSeeder extends Seeder
{
    public const PERMISSIONS = [
        'users.view', 'users.create', 'users.update', 'users.delete',
        'roles.view', 'roles.create', 'roles.update', 'roles.delete',
        'permissions.view', 'permissions.assign',
        'locations.view', 'locations.create', 'locations.update', 'locations.delete',
        'sessions.view', 'sessions.revoke', 'auth_events.view', 'modules.view', 'modules.update',
    ];

    public function run(): void
    {
        app(ModuleRegistry::class)->register();
        Location::query()->select('id')->each(function (Location $location): void {
            foreach (['cashier', 'stock_manager'] as $name) {
                Role::query()->firstOrCreate([
                    'location_id' => $location->getKey(), 'name' => $name, 'guard_name' => 'web',
                ]);
            }
        });
    }
}
