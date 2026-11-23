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
                $method = PaymentMethod::where('type', $type)->lockForUpdate()->first();
                if ($method !== null) {
                    if ($type === 'cash' && $method->requires_reference) {
                        $method->requires_reference = false;
                    }
                    if ($method->is_active && ! $method->available_in_pos) {
                        $method->available_in_pos = true;
                    }
                    if ($method->isDirty()) {
                        $method->save();
                    }

                    continue;
                }
                PaymentMethod::create(['type' => $type,
                    'name' => $name, 'is_active' => true, 'requires_reference' => $reference,
                    'available_in_pos' => true, 'sort_order' => $order,
                ]);
            }
        }, 3);
    }
}
