<?php

namespace App\Modules\POS\Database\Seeders;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentMethods;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PosPaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $actor = User::query()->whereKey(config('treasury.seed_actor_id'))->where('is_active', true)->firstOrFail();

        DB::transaction(function () use ($actor): void {
            foreach (PaymentMethod::query()
                ->where('is_active', true)
                ->whereIn('type', PaymentMethod::TYPES)
                ->orderBy('id')
                ->lockForUpdate()
                ->get() as $method) {
                if ($method->available_in_pos) {
                    continue;
                }

                app(PaymentMethods::class)->save($actor, [
                    'name' => $method->name,
                    'type' => $method->type,
                    'is_active' => $method->is_active,
                    'requires_reference' => $method->requires_reference,
                    'available_in_pos' => true,
                    'sort_order' => $method->sort_order,
                ], $method);
            }
        }, 3);
    }
}
