<?php

namespace App\Modules\Payments\Database\Seeders;

use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Payments\Models\PaymentMethod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            ModuleRegistryLock::acquire();
            foreach ([
                ['cash', 'Cash / نقدي', false, 10],
                ['bank_transfer', 'Bank Transfer / تحويل بنكي', true, 20],
                ['card', 'Card / بطاقة بنكية', true, 30],
            ] as [$type, $name, $reference, $order]) {
                if (PaymentMethod::where('type', $type)->lockForUpdate()->first() !== null) {
                    continue;
                }
                PaymentMethod::create(['type' => $type,
                    'name' => $name, 'is_active' => true, 'requires_reference' => $reference,
                    'available_in_pos' => false, 'sort_order' => $order,
                ]);
            }
        }, 3);
    }
}
