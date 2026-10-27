<?php

namespace App\Modules\Treasury\Database\Seeders;

use App\Modules\AccessManagement\Services\ModuleRegistry;
use Illuminate\Database\Seeder;

/** Register permissions only; accounts, opening balances and module activation require explicit decisions. */
class TreasurySeeder extends Seeder
{
    public function run(): void
    {
        app(ModuleRegistry::class)->register();
    }
}
