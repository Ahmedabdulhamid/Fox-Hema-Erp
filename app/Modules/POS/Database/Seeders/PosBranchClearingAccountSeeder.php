<?php

namespace App\Modules\POS\Database\Seeders;

use Illuminate\Database\Seeder;

class PosBranchClearingAccountSeeder extends Seeder
{
    public function run(): void
    {
        // POS non-cash collections are reporting records and do not provision Treasury accounts.
    }
}
