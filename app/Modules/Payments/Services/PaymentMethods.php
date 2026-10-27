<?php

namespace App\Modules\Payments\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Payments\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentMethods
{
    public function resolve(int $id, ?string $reference, bool $allowInactive = false): PaymentMethod
    {
        $method = PaymentMethod::whereKey($id)->lockForUpdate()->first();
        if ($method === null || (! $allowInactive && ! $method->is_active) || ! in_array($method->type, PaymentMethod::TYPES, true)) {
            throw ValidationException::withMessages(['payment_method_id' => __('payment_methods.validation.active')]);
        }
        if ($method->requires_reference && trim($reference ?? '') === '') {
            throw ValidationException::withMessages(['external_reference' => __('payment_methods.validation.reference')]);
        }

        return $method;
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, array $data, ?PaymentMethod $method = null): PaymentMethod
    {
        return DB::transaction(function () use ($actor, $data, $method): PaymentMethod {
            ModuleRegistryLock::acquire();
            $access = app(PaymentsAccess::class);
            $access->authorize($actor, PaymentsPermissions::VIEW);
            $access->authorize($actor, PaymentsPermissions::MANAGE_METHODS);
            $method = $method === null ? new PaymentMethod : PaymentMethod::whereKey($method->id)->lockForUpdate()->firstOrFail();
            $allowed = ['name', 'type', 'is_active', 'requires_reference', 'available_in_pos', 'sort_order'];
            if (array_diff(array_keys($data), $allowed) !== []) {
                throw ValidationException::withMessages(['method' => __('payment_methods.validation.protected')]);
            }
            $values = array_replace(['is_active' => true, 'requires_reference' => false, 'available_in_pos' => false, 'sort_order' => 0], $method->exists ? $method->only($allowed) : [], $data);
            $validated = Validator::make($values, [
                'name' => ['required', 'string', 'max:150'], 'type' => ['required', Rule::in(PaymentMethod::TYPES)],
                'is_active' => ['required', 'boolean'], 'requires_reference' => ['required', 'boolean'], 'available_in_pos' => ['required', 'boolean'],
                'sort_order' => ['required', 'integer', 'min:0', 'max:4294967295'],
            ], __('payment_methods.validation.rules'), __('payment_methods.fields'))->validate();
            if ($method->exists && $validated['type'] !== $method->type) {
                throw ValidationException::withMessages(['type' => __('payment_methods.validation.immutable')]);
            }
            $method->fill($validated)->save();

            return $method->refresh();
        }, 3);
    }
}
