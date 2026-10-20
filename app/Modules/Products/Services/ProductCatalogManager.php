<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\InventoryCosting\Services\CategoryCostingManager;
use App\Modules\Products\Enums\ProductStatus;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\Size;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductCatalogManager
{
    public function prefix(string $class): string
    {
        return match ($class) {
            Product::class => 'products', Brand::class => 'products.brands', Category::class => 'products.categories', Size::class => 'products.sizes', Color::class => 'products.colors'
        };
    }

    public function save(User $actor, string $class, array $data, ?Model $record = null): Model
    {
        $prefix = $this->prefix($class);
        app(ProductsAccess::class)->authorize($actor, $prefix.'.view');
        app(ProductsAccess::class)->authorize($actor, $prefix.($record ? '.update' : '.create'));

        return DB::transaction(function () use ($actor, $class, $data, $record): Model {
            ModuleRegistryLock::acquire();
            $record = $record ? $class::whereKey($record->id)->lockForUpdate()->firstOrFail() : new $class;
            $isNew = ! $record->exists;
            $costing = Arr::pull($data, 'inventory_costing');
            $simpleVariant = Arr::pull($data, 'simple_variant');
            $assignments = [];
            foreach (['taxes' => 'tax_ids', 'discounts' => 'discount_ids'] as $kind => $field) {
                if (array_key_exists($field, $data)) {
                    abort_unless($class === Product::class, 403);
                    $assignments[$kind] = Arr::pull($data, $field);
                }
            }
            abort_if(($costing !== null && $class !== Category::class) || ($simpleVariant !== null && $class !== Product::class), 403);
            $rules = ['name' => ['required', 'string', 'max:255'], 'is_active' => ['sometimes', 'boolean']];
            if ($class === Product::class) {
                foreach (['has_fixed_selling_price', 'sale_price'] as $field) {
                    if (array_key_exists($field, $data) && (string) $data[$field] !== (string) $record->getAttribute($field)) {
                        app(ProductsAccess::class)->authorize($actor, 'products.update_prices');
                    }
                }
                $rules['has_fixed_selling_price'] = ['sometimes', 'boolean'];
                $rules['sale_price'] = ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D'];
            }
            if ($class === Brand::class) {
                $rules['icon'] = ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    if ($value === $record->icon) {
                        return;
                    }
                    if (! str_starts_with($value, 'brands/icons/') || str_contains($value, '..') || str_contains($value, '\\') || ! Storage::disk('public')->exists($value)) {
                        $fail(__('Choose a valid brand icon.'));
                    }
                }];
            }
            if ($class !== Product::class && $class !== Category::class) {
                $rules['name'][] = Rule::unique($record->getTable(), 'name')->ignore($record->id);
            }
            if ($class === Category::class) {
                if ($isNew) {
                    unset($data['code']);
                } elseif (array_key_exists('code', $data) && $data['code'] !== $record->code) {
                    $this->invalid('code', 'Category codes are generated automatically and cannot be changed.');
                }
                $rules += ['parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')]];
            }
            if (in_array($class, [Size::class, Color::class], true)) {
                $rules['sort_order'] = ['sometimes', 'integer', 'min:0', 'max:4294967295'];
            }
            if ($class === Color::class) {
                $rules['hex_code'] = ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'];
            }
            if ($class === Product::class) {
                $rules += ['description' => ['nullable', 'string'], 'default_sku' => ['nullable', 'string', 'max:255', Rule::unique('products', 'default_sku')->ignore($record->id)], 'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')], 'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')], 'status' => ['sometimes', Rule::enum(ProductStatus::class)]];
            }
            foreach (['name', 'code', 'default_sku'] as $field) {
                if (isset($data[$field]) && is_string($data[$field])) {
                    $data[$field] = trim($data[$field]);
                }
            }
            if (($data['default_sku'] ?? null) === '') {
                $data['default_sku'] = null;
            }
            $data = Validator::make(Arr::only($data, array_keys($rules)), $rules)->validate();
            if ($class === Product::class && ($data['has_fixed_selling_price'] ?? $record->has_fixed_selling_price)
                && ($data['sale_price'] ?? (array_key_exists('sale_price', $data) ? null : $record->sale_price)) === null) {
                $this->invalid('sale_price', 'A valid shared selling price is required.');
            }
            if (($data['status'] ?? null) instanceof ProductStatus) {
                $data['status'] = $data['status']->value;
            }
            if ($class === Category::class && array_key_exists('parent_id', $data) && $data['parent_id'] != $record->parent_id) {
                $id = $data['parent_id'];
                if ($id !== null) {
                    if ($id == $record->id) {
                        $this->invalid('parent_id', 'A category cannot contain a cycle.');
                    }
                    $parent = Category::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                    if (! $parent->is_active) {
                        $this->invalid('parent_id', 'Choose an active parent category.');
                    }
                    if ($parent->parent_id !== null) {
                        $this->invalid('parent_id', 'Choose a root parent category.');
                    }
                }
            }
            if ($class === Product::class) {
                if (isset($data['description'])) {
                    $data['description'] = Str::sanitizeHtml($data['description']);
                }
                foreach (['brand_id' => Brand::class, 'category_id' => Category::class] as $field => $lookup) {
                    if (! empty($data[$field]) && $data[$field] != $record->getAttribute($field) && ! $lookup::findOrFail($data[$field])->is_active) {
                        $this->invalid($field, 'Choose an active catalog value.');
                    }
                }
                if ($record->exists && array_key_exists('category_id', $data) && $data['category_id'] != $record->category_id && app(ProductReferenceChecker::class)->productIsReferenced($record)) {
                    $this->invalid('category_id', 'Referenced products cannot be reclassified in this phase.');
                }
                if (! $record->exists) {
                    $data['status'] ??= 'draft';
                    $data['is_active'] ??= false;
                }
                $status = $data['status'] ?? $record->status?->value ?? 'draft';
                $active = $data['is_active'] ?? $record->is_active ?? false;
                if ($status !== 'active') {
                    $data['is_active'] = false;
                    $active = false;
                }
                if ($active && ! $record->variants()->where('is_active', true)->whereRaw("TRIM(sku) <> ''")->where('sale_price', '>=', 0)->exists()) {
                    $this->invalid('is_active', 'Activation requires a valid active variant.');
                }
            }
            $record->fill($data)->save();

            foreach ($assignments as $kind => $ids) {
                $field = $kind === 'taxes' ? 'tax_ids' : 'discount_ids';
                try {
                    Validator::make([$field => $ids], [$field => ['present', 'array']])->validate();
                    app(ProductAssignmentManager::class)->sync($actor, $record, $kind, $ids);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages([$field => array_merge(...array_values($exception->errors()))]);
                }
            }

            if ($costing !== null && $costing !== []) {
                abort_unless(is_array($costing), 422);
                app(CategoryCostingManager::class)->save($actor, $record, $costing);
            }
            if ($simpleVariant !== null) {
                abort_unless(is_array($simpleVariant), 422);
                $values = Arr::only($simpleVariant, $isNew ? ['sku', 'barcode', 'sale_price', 'cost_price'] : ['sale_price', 'cost_price']);
                if ($isNew && ! array_filter($values, fn ($value): bool => $value !== null && $value !== '')) {
                    $values = [];
                }
                if ($values !== []) {
                    $variant = $isNew ? null : $record->variants()->whereNull('size_id')->whereNull('color_id')->first();
                    if (! $isNew && ($variant === null || $record->variants()->count() !== 1)) {
                        $this->invalid('simple_variant', 'Prices for variable products must be managed per variant.');
                    }
                    if ($variant !== null) {
                        $values['sku'] = $variant->sku;
                    }
                    try {
                        app(ProductVariantManager::class)->save($actor, $record, $values, $variant);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => ['simple_variant.'.$field => $messages])->all());
                    }
                }
            }

            return $record;
        });
    }

    public function canDelete(Model $record): bool
    {
        return match ($record::class) {
            Product::class => $record->status === ProductStatus::Draft && ! $record->variants()->exists(),
            Brand::class => ! $record->products()->exists(),
            Category::class => ! $record->products()->exists() && ! $record->children()->exists() && (! Schema::hasTable('inventory_valuation_settings') || ! DB::table('inventory_valuation_settings')->where('category_id', $record->id)->exists()),
            Size::class, Color::class => ! $record->variants()->exists(),
            default => false,
        };
    }

    public function delete(User $actor, Model $record): bool
    {
        $prefix = $this->prefix($record::class);
        app(ProductsAccess::class)->authorize($actor, $prefix.'.view');
        app(ProductsAccess::class)->authorize($actor, $prefix.'.delete');

        return DB::transaction(function () use ($record): bool {
            ModuleRegistryLock::acquire();
            $record = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if (! $this->canDelete($record)) {
                $this->invalid('record', 'This record is referenced. Deactivate or archive it instead.');
            }

            return (bool) $record->delete();
        });
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
