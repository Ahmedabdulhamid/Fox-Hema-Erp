<?php

namespace App\Modules\AccessManagement\Database\Seeders;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
            $existing = Location::query()->lockForUpdate()->get();
            foreach (self::LOCATIONS as $slug => $data) {
                $sameKey = $existing->firstWhere('slug', $slug);
                $sameName = $existing->filter(fn (Location $location): bool => $this->normalizeName($location->name) === $this->normalizeName($data['name']));
                if (($sameKey !== null && $sameKey->type !== $data['type'])
                    || $sameName->contains(fn (Location $location): bool => $location->slug !== $slug)) {
                    throw new RuntimeException("LocationSeeder conflict for {$data['name']}. Existing locations were preserved; manual approval and reconciliation are required.");
                }
            }
            foreach (self::LOCATIONS as $slug => $data) {
                if ($existing->contains('slug', $slug)) {
                    continue;
                }
                // The unique slug is the setup key; Location generates its immutable code and UUID.
                $location = new Location;
                $location->fill([...$data, 'is_active' => true])->forceFill(['slug' => $slug])->save();
            }
        });
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/[\s_-]+/u', ' ', $name)));
    }
}
