<?php

namespace App\Modules\Treasury\Database\Seeders;

use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Explicit master-data entry point; deliberately absent from the default DatabaseSeeder. */
class TreasuryDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            ModuleRegistryLock::acquire();
            $this->call([BranchCashboxSeeder::class, CentralCashboxSeeder::class]);
        }, 3);
    }
}
