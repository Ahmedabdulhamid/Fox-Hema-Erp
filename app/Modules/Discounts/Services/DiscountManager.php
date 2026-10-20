<?php

namespace App\Modules\Discounts\Services;

use App\Modules\Discounts\Models\Discount;
use App\Modules\Taxes\Services\DefinitionManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class DiscountManager extends DefinitionManager
{
    protected string $module = 'discounts';

    protected string $model = Discount::class;

    protected string $checker = DiscountReferenceChecker::class;

    protected function defaults(): array
    {
        return ['type' => 'percentage', 'scope' => 'product', 'usage' => 'sales', 'is_active' => true, 'is_stackable' => false];
    }

    public function rules(array $data, ?Model $record = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255', Rule::unique('discounts', 'code')->ignore($record?->getKey())],
            'type' => ['required', Rule::in(['percentage', 'fixed'])],
            'value' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,16}(\.\d{1,4})?$/', Rule::when(($data['type'] ?? null) === 'percentage', 'max:100')],
            'scope' => ['required', Rule::in(['product', 'order'])],
            'usage' => ['required', Rule::in(['sales', 'purchases', 'both'])],
            'is_active' => ['required', 'boolean'],
            'is_stackable' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', Rule::when(! empty($data['starts_at']), 'after_or_equal:starts_at')],
        ];
    }
}
