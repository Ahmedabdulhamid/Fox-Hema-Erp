<?php

namespace App\Modules\POS\Database\Seeders;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Services\PosDeviceManagement;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PosDeviceSeeder extends Seeder
{
    public function run(): void
    {
        $actor = User::query()->whereKey(config('treasury.seed_actor_id'))->where('is_active', true)->firstOrFail();

        DB::transaction(function () use ($actor): void {
            ModuleRegistryLock::acquire();

            foreach (Location::query()->where('type', 'branch')->where('is_active', true)->orderBy('id')->get() as $branch) {
                if (PosDevice::query()->where('location_id', $branch->id)->whereNull('revoked_at')->exists()) {
                    continue;
                }

                app(PosDeviceManagement::class)->register($actor, [
                    'name' => Str::limit('POS - '.$branch->name, 191, ''),
                    'location_id' => (int) $branch->id,
                ]);
            }
        }, 3);
    }
}
