<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Purchasing\Models\Supplier;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierService
{
    public function __construct(private readonly PurchasingAccess $access) {}

    public function create(User $actor, array $data): Supplier
    {
        return $this->save($actor, $data);
    }

    public function update(User $actor, Supplier $supplier, array $data): Supplier
    {
        abort_unless($supplier->exists, 404);

        return $this->save($actor, $data, $supplier);
    }

    public function activate(User $actor, Supplier $supplier): Supplier
    {
        return $this->setActive($actor, $supplier, true);
    }

    public function deactivate(User $actor, Supplier $supplier): Supplier
    {
        return $this->setActive($actor, $supplier, false);
    }

    /** Shared master data; historical relationships deliberately include inactive suppliers. */
    public function activeOptions(User $actor): array
    {
        $this->access->authorize($actor, PurchasingPermissions::VIEW);

        return Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    private function save(User $actor, array $data, ?Supplier $supplier = null): Supplier
    {
        try {
            return DB::transaction(function () use ($actor, $data, $supplier): Supplier {
                ModuleRegistryLock::acquire();
                $this->access->authorize($actor, PurchasingPermissions::VIEW);
                $this->access->authorize($actor, $supplier === null ? PurchasingPermissions::CREATE : PurchasingPermissions::UPDATE);
                if (array_key_exists('is_active', $data)) {
                    throw ValidationException::withMessages(['is_active' => __('Use the Activate or Deactivate action to change supplier status.')]);
                }
                $supplier = $supplier === null ? new Supplier : Supplier::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
                $values = array_replace(['payment_terms_days' => 0], $supplier->getAttributes(), $data);
                foreach (['code', 'name', 'contact', 'phone', 'email', 'tax_number'] as $field) {
                    if (isset($values[$field]) && is_string($values[$field])) {
                        $values[$field] = trim($values[$field]);
                    }
                }
                $rules = [
                    'code' => ['required', 'string', 'max:50', Rule::unique('suppliers', 'code')->ignore($supplier)],
                    'name' => ['required', 'string', 'max:255'],
                    'contact' => ['nullable', 'string', 'max:255'],
                    'phone' => ['nullable', 'string', 'max:30'],
                    'email' => ['nullable', 'string', 'email', 'max:255'],
                    'address' => ['nullable', 'string', $this->textLimit('address')],
                    'tax_number' => ['nullable', 'string', 'max:100'],
                    'payment_terms_days' => ['required', 'integer', 'min:0', 'max:65535'],
                    'notes' => ['nullable', 'string', $this->textLimit('notes')],
                ];
                $validated = Validator::make(Arr::only($values, array_keys($rules)), $rules)->validate();
                $supplier->fill($validated);
                if (! $supplier->exists) {
                    $supplier->is_active = true;
                }
                $supplier->save();

                return $supplier;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (isset($data['code']) && Supplier::where('code', trim((string) $data['code']))
                ->when($supplier !== null, fn ($query) => $query->whereKeyNot($supplier->id))->exists()) {
                throw ValidationException::withMessages(['code' => __('validation.unique', ['attribute' => __('Supplier code')])]);
            }
            throw $exception;
        }
    }

    private function textLimit(string $field): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($field): void {
            if (is_string($value) && strlen($value) > 65535) {
                $fail(__($field === 'address' ? 'The address must not exceed 65535 bytes.' : 'The notes must not exceed 65535 bytes.'));
            }
        };
    }

    private function setActive(User $actor, Supplier $supplier, bool $active): Supplier
    {
        abort_unless($supplier->exists, 404);

        return DB::transaction(function () use ($actor, $supplier, $active): Supplier {
            ModuleRegistryLock::acquire();
            $this->access->authorize($actor, PurchasingPermissions::VIEW);
            $this->access->authorize($actor, PurchasingPermissions::DEACTIVATE);
            $supplier = Supplier::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            $supplier->is_active = $active;
            $supplier->save();

            return $supplier;
        });
    }
}
