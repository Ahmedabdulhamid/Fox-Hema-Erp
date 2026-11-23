<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Discounts\Models\Discount;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Size;
use App\Modules\Taxes\Models\Tax;
use Illuminate\Validation\ValidationException;

class ProductImportReferenceResolver
{
    public const MODELS = ['brands' => Brand::class, 'categories' => Category::class, 'colors' => Color::class, 'sizes' => Size::class, 'taxes' => Tax::class, 'discounts' => Discount::class];

    /** @var array<string, array<string, string>> */
    private array $catalogs = [];

    /** @return array<string, string> UUID => employee-readable, unambiguous label */
    public function options(User $actor, string $kind): array
    {
        $allowed = in_array($kind, ['taxes', 'discounts'], true)
            ? app(ProductAssignmentManager::class)->canAssign($actor, $kind)
            : app(ProductsAccess::class)->allows($actor, 'products.'.$kind.'.view');
        if (! $allowed) {
            return [];
        }
        if (isset($this->catalogs[$kind])) {
            return $this->catalogs[$kind];
        }
        $class = self::MODELS[$kind];
        $query = $class::query();
        if ($kind !== 'categories') {
            $query->where('is_active', true);
        }
        if (in_array($kind, ['taxes', 'discounts'], true)) {
            $query->whereIn('id', array_keys(app(ProductAssignmentManager::class)->options($actor, $kind)));
        }
        $records = $query->orderBy('id')->limit(5001)->get()->keyBy('id');
        if ($records->count() > 5000) {
            throw ValidationException::withMessages(['workbook' => __('product_import.employee_reference_limit')]);
        }
        $labels = [];
        $groups = [];
        foreach ($records as $record) {
            if (! $record->is_active) {
                continue;
            }
            $label = $record->name;
            if ($kind === 'categories') {
                $parts = [$label];
                $parent = $record->parent_id;
                $visited = [$record->id => true];
                while ($parent !== null) {
                    if (isset($visited[$parent]) || ! $records->has($parent)) {
                        $parts = [];
                        break;
                    }
                    $visited[$parent] = true;
                    $ancestor = $records->get($parent);
                    array_unshift($parts, $ancestor->name);
                    $parent = $ancestor->parent_id;
                }
                if ($parts === []) {
                    continue;
                }
                $label = implode(' / ', $parts);
            } elseif ($kind === 'taxes' || $kind === 'discounts') {
                $label .= ' — '.($record->code ?: ($kind === 'taxes' ? $record->rate.'%' : $record->value.($record->type === 'percentage' ? '%' : '')));
            }
            $labels[$record->uuid] = $label;
            $normalized = app(ProductImportProductIdentifiers::class)->normalizeName($label);
            $groups[$normalized][] = $record;
        }
        foreach ($groups as $group) {
            if (count($group) <= 1) {
                continue;
            }
            foreach ($group as $record) {
                unset($labels[$record->uuid]);
            }
        }

        return $this->catalogs[$kind] = $labels;
    }

    public function resolve(User $actor, string $kind, string $value, string $field, ?string $expectedUuid = null): string
    {
        if ($value === '' && $expectedUuid === null) {
            return '';
        }
        $normalize = app(ProductImportProductIdentifiers::class)->normalizeName(...);
        $matches = array_keys(array_filter($this->options($actor, $kind), fn (string $label): bool => $normalize($label) === $normalize($value)));
        if (count($matches) !== 1 || ($expectedUuid !== null && $matches[0] !== $expectedUuid)) {
            throw ValidationException::withMessages([$field => __('product_import.employee_reference_missing', ['value' => $value, 'kind' => __('product_import.employee_kinds.'.$kind)])]);
        }

        return $matches[0];
    }
}
