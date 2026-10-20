<?php

namespace App\Modules\AccessManagement\Database\Seeders;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocationSeeder extends Seeder
{
    private const LOCATIONS = [
        'central-warehouse' => ['name' => 'Central Warehouse', 'type' => 'warehouse'],
        'branch-1' => ['name' => 'Branch 1', 'type' => 'branch'],
        'branch-2' => ['name' => 'Branch 2', 'type' => 'branch'],
        'branch-3' => ['name' => 'Branch 3', 'type' => 'branch'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            ModuleRegistryLock::acquire();
            if (Location::query()->lockForUpdate()->first() !== null) {
                return;
            }
            foreach (self::LOCATIONS as $slug => $data) {
                $location = new Location;
                $location->fill([...$data, 'is_active' => true])->forceFill(['slug' => $slug])->save();
            }
        }, 3);
    }
}
