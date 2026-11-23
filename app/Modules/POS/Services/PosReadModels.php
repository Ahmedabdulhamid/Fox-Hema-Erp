<?php

namespace App\Modules\POS\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosDevicePaymentMethod;
use App\Modules\POS\Models\PosSession;
use App\Modules\Treasury\Models\TreasuryAccount;
use Illuminate\Database\Eloquent\Builder;

class PosReadModels
{
    /**
     * @return list<array{id: int, code: string, name: string, type: string, account_id: ?int, account_name: ?string, active: bool}>
     */
    public function paymentMethodConfigurations(User $actor, int $deviceId): array
    {
        $device = PosDevice::query()->select(['id', 'location_id'])->whereKey($deviceId)->firstOrFail();
        app(PosAccess::class)->authorize($actor, PosPermissions::DEVICES_MANAGE, (int) $device->location_id);

        return PosDevicePaymentMethod::query()
            ->where('pos_device_id', $deviceId)
            ->with([
                'paymentMethod:id,code,name,type,is_active,available_in_pos,requires_reference',
                'treasuryAccount:id,name,type,location_id,ownership,currency,is_active',
            ])
            ->orderBy('payment_method_id')
            ->get()
            ->map(fn (PosDevicePaymentMethod $configuration): array => [
                'id' => (int) $configuration->payment_method_id,
                'code' => $configuration->paymentMethod->code,
                'name' => $configuration->paymentMethod->name,
                'type' => $configuration->paymentMethod->type,
                'account_id' => $configuration->treasury_account_id === null ? null : (int) $configuration->treasury_account_id,
                'account_name' => $configuration->paymentMethod->type === 'cash'
                    ? $configuration->treasuryAccount?->name
                    : null,
                'active' => (bool) $configuration->is_active
                    && $this->mappingIsActive($configuration, (int) $device->location_id),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, code: string, name: string, type: string, account_id: ?int, account_name: ?string, requires_reference: bool}>
     */
    public function checkoutTenderOptions(User $actor, int $deviceId, int $sessionId): array
    {
        $device = PosDevice::query()->select(['id', 'location_id'])->whereKey($deviceId)->firstOrFail();
        app(PosAccess::class)->authorize($actor, PosPermissions::CHECKOUT, (int) $device->location_id);

        $session = PosSession::query()
            ->whereKey($sessionId)
            ->where('pos_device_id', $deviceId)
            ->where('location_id', $device->location_id)
            ->when(! PosCheckoutService::isPostingLate($sessionId), fn ($query) => $query->where('status', 'open'))
            ->firstOrFail();

        $configurations = PosDevicePaymentMethod::query()
            ->where('pos_device_id', $deviceId)
            ->where('is_active', true)
            ->with([
                'paymentMethod:id,code,name,type,is_active,available_in_pos,requires_reference',
                'treasuryAccount:id,name,type,location_id,ownership,currency,is_active',
            ])
            ->orderBy('payment_method_id')
            ->get()
            ->filter(function (PosDevicePaymentMethod $configuration) use ($session, $device): bool {
                if (! $this->mappingIsActive($configuration, (int) $device->location_id)) {
                    return false;
                }

                return $configuration->paymentMethod->type !== 'cash'
                    || (int) $configuration->treasury_account_id === (int) $session->cash_treasury_account_id;
            });

        return $configurations
            ->map(fn (PosDevicePaymentMethod $configuration): array => [
                'id' => (int) $configuration->payment_method_id,
                'code' => $configuration->paymentMethod->code,
                'name' => $configuration->paymentMethod->name,
                'type' => $configuration->paymentMethod->type,
                'account_id' => $configuration->treasury_account_id === null ? null : (int) $configuration->treasury_account_id,
                'account_name' => $configuration->paymentMethod->type === 'cash'
                    ? $configuration->treasuryAccount?->name
                    : null,
                'requires_reference' => $configuration->paymentMethod->type !== 'cash'
                    && (bool) $configuration->paymentMethod->requires_reference,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function paymentMethodOptions(User $actor, int $deviceId): array
    {
        $device = PosDevice::query()->select(['id', 'location_id'])->whereKey($deviceId)->firstOrFail();
        app(PosAccess::class)->authorize($actor, PosPermissions::DEVICES_MANAGE, (int) $device->location_id);

        return PaymentMethod::query()
            ->where('is_active', true)
            ->where('available_in_pos', true)
            ->whereIn('type', PaymentMethod::TYPES)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function treasuryAccountOptions(User $actor, int $deviceId, int $paymentMethodId): array
    {
        $device = PosDevice::query()->select(['id', 'location_id'])->whereKey($deviceId)->firstOrFail();
        app(PosAccess::class)->authorize($actor, PosPermissions::DEVICES_MANAGE, (int) $device->location_id);
        $method = PaymentMethod::query()->select(['id', 'type'])->whereKey($paymentMethodId)->firstOrFail();
        if ($method->type !== 'cash') {
            return [];
        }
        $types = ['branch_cashbox', 'location_cashbox'];

        $existingAccountId = PosDevicePaymentMethod::query()
            ->where('pos_device_id', $deviceId)
            ->where('payment_method_id', $paymentMethodId)
            ->value('treasury_account_id');

        return TreasuryAccount::query()
            ->where('location_id', $device->location_id)
            ->where('ownership', 'location')
            ->where('currency', 'EGP')
            ->whereIn('type', $types)
            ->where(function (Builder $query) use ($existingAccountId): void {
                $query->where('is_active', true);
                if ($existingAccountId !== null) {
                    $query->orWhereKey($existingAccountId);
                }
            })
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function mappingIsActive(PosDevicePaymentMethod $configuration, int $locationId): bool
    {
        $method = $configuration->paymentMethod;
        $account = $configuration->treasuryAccount;

        if (! $method->is_active || ! $method->available_in_pos || ! in_array($method->type, PaymentMethod::TYPES, true)) {
            return false;
        }
        if ($method->type !== 'cash') {
            return true;
        }

        return $account !== null
            && $account->is_active
            && (int) $account->location_id === $locationId
            && $account->ownership === 'location'
            && $account->currency === 'EGP'
            && in_array($account->type, ['branch_cashbox', 'location_cashbox'], true);
    }
}
