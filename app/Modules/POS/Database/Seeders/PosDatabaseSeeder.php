<?php

namespace App\Modules\POS\Database\Seeders;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Database\Seeders\PaymentsDatabaseSeeder;
use App\Modules\Treasury\Database\Seeders\TreasuryDatabaseSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class PosDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new LogicException('POS demo configuration seeding is restricted to the local environment.');
        }

        $this->call([
            AccessManagementSeeder::class,
            PaymentsDatabaseSeeder::class,
        ]);

        $actor = User::query()
            ->whereKey(config('treasury.seed_actor_id'))
            ->where('is_active', true)
            ->first();
        if ($actor === null || ! $actor->isGlobalSuperAdmin()) {
            throw new LogicException('Configure TREASURY_SEED_ACTOR_ID with an active global administrator before seeding POS.');
        }

        foreach (['pos', 'payments', 'treasury'] as $moduleCode) {
            if (! Module::query()->where('code', $moduleCode)->where('is_active', true)->exists()) {
                throw new LogicException("Activate the {$moduleCode} module before seeding POS configuration.");
            }
        }

        $this->call(TreasuryDatabaseSeeder::class);

        DB::transaction(function (): void {
            $this->call([
                PosPaymentMethodSeeder::class,
                PosDeviceSeeder::class,
                PosDevicePaymentMethodSeeder::class,
            ]);
        }, 3);

        $this->command?->info('POS devices, payment methods and branch Treasury mappings are ready.');
    }
}
