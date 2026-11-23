<?php

namespace App\Modules\POS\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosDevicePaymentMethod;
use App\Modules\POS\Models\PosSession;
use App\Modules\Treasury\Models\TreasuryAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PosDeviceManagement
{
    /**
     * @param  array{name: string, location_id: int|string}  $data
     */
    public function register(User $actor, array $data): PosDevice
    {
        return DB::transaction(function () use ($actor, $data): PosDevice {
            ModuleRegistryLock::acquire();
            $data = $this->validated($data, ['name', 'location_id']);
            $location = Location::whereKey($data['location_id'])->lockForUpdate()->firstOrFail();
            app(PosAccess::class)->authorize($actor, PosPermissions::DEVICES_MANAGE, (int) $location->id);
            $this->requireActiveBranch($location);

            $device = PosDevice::create([
                'name' => $data['name'],
                'location_id' => $location->id,
                'is_active' => true,
                'registered_at' => now(),
                'registered_by' => $actor->id,
            ]);
            app(AuditLogger::class)->record('pos_device_registered', metadata: [
                'pos_device_id' => $device->id,
                'location_id' => $location->id,
                'device_code' => $device->device_code,
            ], actor: $actor);

            return $device;
        }, 3);
    }

    /**
     * @param  array{name?: string, location_id?: int|string}  $data
     */
    public function update(User $actor, PosDevice $device, array $data): PosDevice
    {
        return DB::transaction(function () use ($actor, $device, $data): PosDevice {
            ModuleRegistryLock::acquire();
            $stored = PosDevice::whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            app(PosAccess::class)->authorize($actor, PosPermissions::DEVICES_MANAGE, (int) $stored->location_id);
            $data = $this->validated($data, ['name', 'location_id'], partial: true);
            $this->assertNoActiveShift($stored);
            $oldLocationId = (int) $stored->location_id;

            if (isset($data['location_id']) && (int) $data['location_id'] !== $oldLocationId) {
                app(PosAccess::class)->authorize($actor, PosPermissions::DEVICES_MANAGE, (int) $data['location_id']);
                $location = Location::whereKey($data['location_id'])->lockForUpdate()->firstOrFail();
                $this->requireActiveBranch($location);
                if (PosSession::where('pos_device_id', $stored->id)->exists()) {
                    throw ValidationException::withMessages([
                        'location_id' => __('pos.validation.device_with_history'),
                    ]);
                }
                $stored->paymentConfigurations()->where('is_active', true)->each(
                    fn (PosDevicePaymentMethod $configuration) => $configuration->update(['is_active' => false]),
                );
            }

            $stored->fill($data)->save();
            app(AuditLogger::class)->record('pos_device_updated', metadata: [
                'pos_device_id' => $stored->id,
                'old_location_id' => $oldLocationId,
                'location_id' => $stored->location_id,
            ], actor: $actor);

            return $stored;
        }, 3);
    }

    public function setActive(User $actor, PosDevice $device, bool $isActive): PosDevice
    {
        return DB::transaction(function () use ($actor, $device, $isActive): PosDevice {
            ModuleRegistryLock::acquire();
            $stored = PosDevice::whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            app(PosAccess::class)->authorize($actor, PosPermissions::DEVICES_MANAGE, (int) $stored->location_id);
            $this->assertNoActiveShift($stored);
            if ($isActive && $stored->revoked_at !== null) {
                throw ValidationException::withMessages([
                    'device' => __('pos.validation.revoked_device_reactivation'),
                ]);
            }

            if ($stored->is_active !== $isActive) {
                $stored->is_active = $isActive;
                $stored->save();
                app(AuditLogger::class)->record($isActive ? 'pos_device_activated' : 'pos_device_deactivated', metadata: [
                    'pos_device_id' => $stored->id,
                    'location_id' => $stored->location_id,
                ], actor: $actor);
            }

            return $stored;
        }, 3);
    }

    public function revoke(User $actor, PosDevice $device): PosDevice
    {
        return DB::transaction(function () use ($actor, $device): PosDevice {
            ModuleRegistryLock::acquire();
            $stored = PosDevice::whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            app(PosAccess::class)->authorize($actor, PosPermissions::DEVICES_MANAGE, (int) $stored->location_id);
            $this->assertNoActiveShift($stored);

            if ($stored->revoked_at === null) {
                $stored->forceFill(['is_active' => false, 'revoked_at' => now()])->save();
                app(AuditLogger::class)->record('pos_device_revoked', metadata: [
                    'pos_device_id' => $stored->id,
                    'location_id' => $stored->location_id,
                ], actor: $actor);
            }

            return $stored;
        }, 3);
    }

    public function configurePaymentMethod(
        User $actor,
        PosDevice $device,
        int $paymentMethodId,
        ?int $treasuryAccountId,
        bool $isActive = true,
    ): PosDevicePaymentMethod {
        return DB::transaction(function () use ($actor, $device, $paymentMethodId, $treasuryAccountId, $isActive): PosDevicePaymentMethod {
            ModuleRegistryLock::acquire();
            $storedDevice = PosDevice::whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            app(PosAccess::class)->authorize($actor, PosPermissions::DEVICES_MANAGE, (int) $storedDevice->location_id);
            if ($storedDevice->revoked_at !== null) {
                throw ValidationException::withMessages([
                    'pos_device_id' => __('pos.validation.revoked_device_configuration'),
                ]);
            }
            $activeShift = $this->activeShiftForDevice($storedDevice);

            $method = PaymentMethod::whereKey($paymentMethodId)->lockForUpdate()->firstOrFail();
            $account = $treasuryAccountId === null
                ? null
                : TreasuryAccount::whereKey($treasuryAccountId)->lockForUpdate()->firstOrFail();
            $this->validatePaymentMapping($method, $account, (int) $storedDevice->location_id, $isActive);

            $configuration = PosDevicePaymentMethod::where('pos_device_id', $storedDevice->id)
                ->where('payment_method_id', $method->id)
                ->lockForUpdate()
                ->first();
            if ($activeShift !== null && $method->type === 'cash') {
                $this->assertCashAssignmentUnchangedDuringActiveShift(
                    $activeShift,
                    $configuration,
                    $account === null ? null : (int) $account->id,
                );
            }
            $originalAccountId = $configuration?->treasury_account_id;

            if ($configuration === null) {
                $configuration = PosDevicePaymentMethod::create([
                    'uuid' => (string) Str::uuid7(),
                    'pos_device_id' => $storedDevice->id,
                    'payment_method_id' => $method->id,
                    'treasury_account_id' => $account?->id,
                    'is_active' => $isActive,
                ]);
            } else {
                $configuration->fill([
                    'treasury_account_id' => $account?->id,
                    'is_active' => $isActive,
                ])->save();
            }

            app(AuditLogger::class)->record('pos_device_payment_method_configured', metadata: [
                'pos_device_id' => $storedDevice->id,
                'payment_method_id' => $method->id,
                'previous_treasury_account_id' => $originalAccountId,
                'treasury_account_id' => $account?->id,
                'is_active' => $isActive,
            ], actor: $actor);

            return $configuration->load(['paymentMethod:id,name,type', 'treasuryAccount:id,name,type']);
        }, 3);
    }

    /**
     * @param  list<array{payment_method_id: int|string, treasury_account_id: int|string|null, is_active: bool}>  $paymentMethods
     */
    public function configurePaymentMethods(User $actor, PosDevice $device, array $paymentMethods): PosDevicePaymentMethod
    {
        return DB::transaction(function () use ($actor, $device, $paymentMethods): PosDevicePaymentMethod {
            ModuleRegistryLock::acquire();
            $storedDevice = PosDevice::whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            app(PosAccess::class)->authorize($actor, PosPermissions::DEVICES_MANAGE, (int) $storedDevice->location_id);
            if ($storedDevice->revoked_at !== null) {
                throw ValidationException::withMessages([
                    'pos_device_id' => __('pos.validation.revoked_device_configuration'),
                ]);
            }
            $activeShift = $this->activeShiftForDevice($storedDevice);

            $validated = Validator::make(['payment_methods' => $paymentMethods], [
                'payment_methods' => ['array'],
                'payment_methods.*' => ['required', 'array:payment_method_id,treasury_account_id,is_active'],
                'payment_methods.*.payment_method_id' => ['required', 'integer', 'min:1'],
                'payment_methods.*.treasury_account_id' => ['nullable', 'integer', 'min:1'],
                'payment_methods.*.is_active' => ['required', 'boolean'],
            ])->validate()['payment_methods'];
            $methodIds = array_map(fn (array $mapping): int => (int) $mapping['payment_method_id'], $validated);
            if (count($methodIds) !== count(array_unique($methodIds))) {
                throw ValidationException::withMessages([
                    'payment_methods' => __('pos.validation.duplicate_payment_method'),
                ]);
            }
            $existing = PosDevicePaymentMethod::where('pos_device_id', $storedDevice->id)
                ->with('paymentMethod:id,type')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('payment_method_id');
            if ($validated === [] && $existing->isEmpty()) {
                throw ValidationException::withMessages([
                    'payment_methods' => __('pos.validation.payment_configuration_required'),
                ]);
            }

            $methods = PaymentMethod::whereKey($methodIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $accountIds = array_values(array_unique(array_filter(array_map(
                fn (array $mapping): ?int => filled($mapping['treasury_account_id'] ?? null)
                    ? (int) $mapping['treasury_account_id']
                    : null,
                $validated,
            ))));
            $accounts = TreasuryAccount::whereKey($accountIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($methods->count() !== count(array_unique($methodIds))
                || $accounts->count() !== count($accountIds)) {
                throw ValidationException::withMessages([
                    'payment_methods' => __('pos.validation.invalid_payment_mapping'),
                ]);
            }
            if ($activeShift !== null) {
                $validatedByMethod = collect($validated)->keyBy(
                    fn (array $mapping): int => (int) $mapping['payment_method_id'],
                );
                foreach ($existing as $configuration) {
                    if ($configuration->paymentMethod->type !== 'cash') {
                        continue;
                    }

                    $mapping = $validatedByMethod->get((int) $configuration->payment_method_id);
                    $accountId = $mapping !== null && filled($mapping['treasury_account_id'] ?? null)
                        ? (int) $mapping['treasury_account_id']
                        : null;
                    $isActive = $mapping !== null && (bool) $mapping['is_active'];
                    $this->assertCashAssignmentUnchangedDuringActiveShift(
                        $activeShift,
                        $configuration,
                        $accountId,
                    );
                }
                foreach ($validated as $mapping) {
                    $methodId = (int) $mapping['payment_method_id'];
                    if ($methods->get($methodId)->type === 'cash' && ! $existing->has($methodId)) {
                        $this->assertCashAssignmentUnchangedDuringActiveShift(
                            $activeShift,
                            null,
                            filled($mapping['treasury_account_id'] ?? null)
                                ? (int) $mapping['treasury_account_id']
                                : null,
                        );
                    }
                }
            }

            $configured = collect();
            foreach ($validated as $mapping) {
                $method = $methods->get((int) $mapping['payment_method_id']);
                $account = filled($mapping['treasury_account_id'] ?? null)
                    ? $accounts->get((int) $mapping['treasury_account_id'])
                    : null;
                $isActive = (bool) $mapping['is_active'];
                $this->validatePaymentMapping($method, $account, (int) $storedDevice->location_id, $isActive);
                $accountId = $account?->id;

                $configuration = $existing->get((int) $method->id);
                if ($configuration === null) {
                    $configuration = PosDevicePaymentMethod::create([
                        'uuid' => (string) Str::uuid7(),
                        'pos_device_id' => $storedDevice->id,
                        'payment_method_id' => $method->id,
                        'treasury_account_id' => $accountId,
                        'is_active' => $isActive,
                    ]);
                } else {
                    $configuration->fill([
                        'treasury_account_id' => $accountId,
                        'is_active' => $isActive,
                    ])->save();
                }
                $configured->push($configuration);
                $existing->forget((int) $method->id);
            }

            $existing->each(fn (PosDevicePaymentMethod $configuration) => $configuration->update(['is_active' => false]));
            app(AuditLogger::class)->record('pos_device_payment_methods_synchronized', metadata: [
                'pos_device_id' => $storedDevice->id,
                'payment_method_ids' => $methodIds,
                'deactivated_payment_method_ids' => $existing->pluck('payment_method_id')->all(),
            ], actor: $actor);

            return $configured->first() ?? PosDevicePaymentMethod::where('pos_device_id', $storedDevice->id)->firstOrFail();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private function validated(
        array $data,
        array $allowed,
        bool $partial = false,
    ): array {
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw ValidationException::withMessages(['device' => __('pos.validation.unsupported_device_fields')]);
        }

        $rules = [
            'name' => ['required', 'string', 'max:191'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
        ];
        if ($partial) {
            foreach ($rules as $field => $fieldRules) {
                if (! array_key_exists($field, $data)) {
                    unset($rules[$field]);
                }
            }
        }
        Validator::make($data, $rules)->validate();

        if (isset($data['name'])) {
            $data['name'] = trim((string) $data['name']);
        }

        return $data;
    }

    private function validatePaymentMapping(
        PaymentMethod $method,
        ?TreasuryAccount $account,
        int $locationId,
        bool $isActive,
    ): void {
        $validCashAccount = $account !== null
            && (! $isActive || $account->is_active)
            && (int) $account->location_id === $locationId
            && $account->ownership === 'location'
            && $account->currency === 'EGP'
            && in_array($account->type, ['branch_cashbox', 'location_cashbox'], true);
        $validTreasuryMapping = $method->type === 'cash'
            ? $validCashAccount
            : $account === null;
        if (($isActive && (! $method->is_active || ! $method->available_in_pos))
            || ! in_array($method->type, PaymentMethod::TYPES, true)
            || ! $validTreasuryMapping) {
            throw ValidationException::withMessages([
                'treasury_account_id' => __('pos.validation.invalid_payment_mapping'),
            ]);
        }
    }

    private function requireActiveBranch(Location $location): void
    {
        if ($location->type !== 'branch' || ! $location->is_active) {
            throw ValidationException::withMessages([
                'location_id' => __('pos.validation.invalid_branch'),
            ]);
        }
    }

    private function assertNoActiveShift(PosDevice $device, string $attribute = 'device'): void
    {
        if (PosSession::where('pos_device_id', $device->id)->whereIn('status', ['open', 'closing'])->exists()) {
            throw ValidationException::withMessages([
                $attribute => __('pos.validation.device_has_active_shift'),
            ]);
        }
    }

    private function activeShiftForDevice(PosDevice $device): ?PosSession
    {
        return PosSession::query()
            ->where('pos_device_id', $device->id)
            ->whereIn('status', ['open', 'closing'])
            ->lockForUpdate()
            ->first();
    }

    private function assertCashAssignmentUnchangedDuringActiveShift(
        PosSession $activeShift,
        ?PosDevicePaymentMethod $existing,
        ?int $treasuryAccountId,
    ): void {
        $shiftCashboxId = $activeShift->cash_treasury_account_id === null
            ? null
            : (int) $activeShift->cash_treasury_account_id;
        $existingCashboxId = $existing?->treasury_account_id === null
            ? null
            : (int) $existing->treasury_account_id;

        if ($existing === null
            || $shiftCashboxId === null
            || $existingCashboxId !== $shiftCashboxId
            || $treasuryAccountId !== $existingCashboxId) {
            throw ValidationException::withMessages([
                'pos_device_id' => __('pos.validation.cash_mapping_locked_during_shift'),
            ]);
        }
    }
}
