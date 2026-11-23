<?php

namespace App\Modules\AccessManagement\Database\Seeders;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\AccessManagement\Services\SuperAdminProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

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
        $this->call(LocationSeeder::class);
        Module::query()->update(['is_active' => true]);

        Location::query()->select('id')->each(function (Location $location): void {
            foreach (['cashier', 'stock_manager'] as $name) {
                Role::query()->firstOrCreate([
                    'location_id' => $location->getKey(), 'name' => $name, 'guard_name' => 'web',
                ]);
            }
        });

        $adminConfiguration = [
            'FOX_HEMA_SUPER_ADMIN_EMAIL' => env('FOX_HEMA_SUPER_ADMIN_EMAIL'),
            'SEED_ADMIN_NAME' => env('SEED_ADMIN_NAME'),
            'FOX_HEMA_SUPER_ADMIN_PASSWORD' => env('FOX_HEMA_SUPER_ADMIN_PASSWORD'),
        ];
        $missingKeys = array_keys(array_filter($adminConfiguration, fn ($value): bool => ! is_string($value) || trim($value) === ''));
        if ($missingKeys !== []) {
            throw new LogicException('Missing required environment keys: '.implode(', ', $missingKeys));
        }

        $adminEmail = $adminConfiguration['FOX_HEMA_SUPER_ADMIN_EMAIL'];
        $adminName = $adminConfiguration['SEED_ADMIN_NAME'];
        $adminPassword = $adminConfiguration['FOX_HEMA_SUPER_ADMIN_PASSWORD'];
        $assignExisting = DB::table('users')->where('email', $adminEmail)->exists();
        $admin = app(SuperAdminProvisioner::class)->provision(
            $adminEmail,
            $assignExisting,
            $adminName,
            $adminPassword,
        );
        $updates = [];
        if ($admin->name !== $adminName) {
            $updates['name'] = $adminName;
        }
        if (! Hash::check($adminPassword, $admin->password)) {
            $updates['password'] = Hash::make($adminPassword);
        }
        if ($updates !== []) {
            $admin->forceFill($updates)->save();
        }
        $admin->locations()->sync(Location::query()->where('is_active', true)->pluck('locations.id'));
    }
}
