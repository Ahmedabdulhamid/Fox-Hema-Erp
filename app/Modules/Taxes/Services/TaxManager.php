<?php

namespace App\Modules\Taxes\Services;

use App\Modules\Taxes\Models\Tax;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class TaxManager extends DefinitionManager
{
    protected string $module = 'taxes';

    protected string $model = Tax::class;

    protected string $checker = TaxReferenceChecker::class;

    protected function defaults(): array
    {
        return ['type' => 'percentage', 'rate' => '0.0000', 'price_inclusion' => 'excluded', 'usage' => 'sales',
            'purchase_treatment' => null, 'is_active' => true];
    }

    public function rules(array $data, ?Model $record = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255', Rule::unique('taxes', 'code')->ignore($record?->getKey())],
            'type' => ['required', Rule::in(['percentage', 'fixed'])],
            'rate' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,8}(\.\d{1,4})?$/', Rule::when(($data['type'] ?? null) === 'percentage', 'max:100')],
            'price_inclusion' => ['required', Rule::in(['excluded', 'included'])],
            'usage' => ['required', Rule::in(['sales', 'purchases', 'both'])],
            'purchase_treatment' => ['nullable', Rule::in(['recoverable', 'capitalizable']),
                Rule::requiredIf(in_array($data['usage'] ?? null, ['purchases', 'both'], true)
                    && BigDecimal::of((string) ($data['rate'] ?? '0'))->isPositive())],
            'is_active' => ['required', 'boolean'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', Rule::when(! empty($data['effective_from']), 'after_or_equal:effective_from')],
        ];
    }
}
