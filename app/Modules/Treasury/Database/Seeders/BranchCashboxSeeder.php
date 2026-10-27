<?php

namespace App\Modules\Treasury\Database\Seeders;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use Illuminate\Support\Str;

class BranchCashboxSeeder extends TreasuryCashboxSeeder
{
    public function run(): void
    {
        $this->initialize(function (User $actor): void {
            $branches = Location::where('type', 'branch')->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
            foreach ($branches as $branch) {
                $this->ensureCashbox($actor, [
                    'code' => 'CASH-BR-'.(int) $branch->id,
                    'name' => Str::limit("Branch Cashbox \u{2014} {$branch->name}", 191, ''),
                    'type' => 'branch_cashbox', 'ownership' => 'location', 'location_id' => (int) $branch->id,
                    'currency' => 'EGP', 'is_active' => true,
                ]);
            }
        });
    }
}
