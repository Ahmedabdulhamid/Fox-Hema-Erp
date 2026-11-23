<?php

namespace App\Modules\POS\Database\Seeders;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosDevicePaymentMethod;
use App\Modules\POS\Services\PosDeviceManagement;
use App\Modules\Treasury\Models\TreasuryAccount;
use Illuminate\Database\Seeder;

class PosDevicePaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $actor = User::query()->whereKey(config('treasury.seed_actor_id'))->where('is_active', true)->firstOrFail();
        $methods = PaymentMethod::query()
            ->where('is_active', true)
            ->where('available_in_pos', true)
            ->whereIn('type', PaymentMethod::TYPES)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach (PosDevice::query()->whereNull('revoked_at')->orderBy('id')->get() as $device) {
            foreach ($methods as $method) {
                if (PosDevicePaymentMethod::query()
                    ->where('pos_device_id', $device->id)
                    ->where('payment_method_id', $method->id)
                    ->exists()) {
                    continue;
                }

                $accountId = null;
                if ($method->type === 'cash') {
                    $accounts = TreasuryAccount::query()
                        ->where('location_id', $device->location_id)
                        ->where('ownership', 'location')
                        ->where('currency', 'EGP')
                        ->where('is_active', true)
                        ->whereIn('type', ['branch_cashbox', 'location_cashbox'])
                        ->orderBy('id')
                        ->limit(2)
                        ->get();
                    if ($accounts->count() !== 1) {
                        continue;
                    }
                    $accountId = (int) $accounts->first()->id;
                }

                app(PosDeviceManagement::class)->configurePaymentMethod(
                    $actor,
                    $device,
                    (int) $method->id,
                    $accountId,
                );
            }
        }
    }
}
