<?php

namespace App\Modules\Treasury\Database\Seeders;

use App\Modules\AccessManagement\Models\User;

class CentralCashboxSeeder extends TreasuryCashboxSeeder
{
    public function run(): void
    {
        $this->initialize(function (User $actor): void {
            $this->ensureCashbox($actor, [
                'code' => 'CASH-CENTRAL', 'name' => 'Central Administration Cashbox',
                'type' => 'central_cashbox', 'ownership' => 'central', 'location_id' => null,
                'currency' => 'EGP', 'is_active' => true,
            ]);
        });
    }
}
