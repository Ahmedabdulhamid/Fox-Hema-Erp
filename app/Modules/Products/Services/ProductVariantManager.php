<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use Brick\Math\BigDecimal;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductVariantManager
{
    public function save(User $actor, Product $product, array $data, ?ProductVariant $variant = null): ProductVariant
    {
        $access = app(ProductsAccess::class);
        $access->authorize($actor, 'products.view');
        $access->authorize($actor, 'products.variants.'.($variant ? 'update' : 'create'));

        return DB::transaction(function () use ($actor, $product, $data, $variant): ProductVariant {
            ModuleRegistryLock::acquire();
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $variant = $variant ? $product->variants()->whereKey($variant->id)->lockForUpdate()->firstOrFail() : new ProductVariant(['product_id' => $product->id]);
            if (array_key_exists('product_id', $data) && $data['product_id'] != $product->id) {
                abort(403);
            }
            $data = $this->validateData($actor, $data, $variant);
            if ($product->has_fixed_selling_price) {
                if (array_key_exists('sale_price', $data)
                    && ! BigDecimal::of($data['sale_price'])->isEqualTo($variant->exists ? $variant->sale_price : $product->sale_price)) {
                    $this->invalid('sale_price', 'Edit the shared selling price on the product.');
                }
                if (! $variant->exists) {
                    $data['sale_price'] = $product->sale_price;
                }
            } elseif (! $variant->exists && ! array_key_exists('sale_price', $data) && $product->sale_price !== null) {
                $data['sale_price'] = $product->sale_price;
            }
            if ($variant->isUnallocated() && (! empty($data['size_id']) || ! empty($data['color_id']))) {
                $this->invalid('size_id', 'Unallocated variant identity cannot be changed.');
            }
            if ($variant->exists && app(ProductReferenceChecker::class)->variantIsReferenced($variant)) {
                foreach (['sku', 'barcode', 'size_id', 'color_id'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $old = $variant->getAttribute($field);
                        $new = $data[$field];
                        if (in_array($field, ['size_id', 'color_id'], true)) {
                            $old = $old === null ? null : (int) $old;
                            $new = $new === null ? null : (int) $new;
                        }
                        if ($new !== $old) {
                            $this->invalid($field, 'Referenced variant identity cannot be changed.');
                        }
                    }
                }
            }
            $variant->fill($data);
            $others = $product->variants()->whereNull('unallocated_product_id')->when($variant->exists, fn ($query) => $query->whereKeyNot($variant->id));
            $duplicate = (clone $others)->where('size_id', $variant->size_id)->where('color_id', $variant->color_id)->exists();
            $base = $variant->size_id === null && $variant->color_id === null;
            if ($base && $product->variants()->whereNotNull('unallocated_product_id')->exists() && ! $variant->isUnallocated()) {
                $this->invalid('size_id', 'A product with unallocated inventory requires explicit size variants.');
            }
            $mixed = $base ? (clone $others)->exists() : (clone $others)->whereNull('size_id')->whereNull('color_id')->exists();
            if (! $variant->isUnallocated() && ($duplicate || $mixed)) {
                $this->invalid('size_id', 'Duplicate combinations and mixing simple/variable variants are not allowed.');
            }
            if (! ($variant->is_active ?? true) && $product->is_active && ! (clone $others)->where('is_active', true)->exists()) {
                $this->invalid('is_active', 'An active product must retain an active variant.');
            }
            $variant->save();

            return $variant;
        });
    }

    public function validateData(User $actor, array $data, ?ProductVariant $variant = null): array
    {
        $access = app(ProductsAccess::class);
        $access->authorize($actor, 'products.view');
        $access->authorize($actor, 'products.variants.'.($variant?->exists ? 'update' : 'create'));
        $variant ??= new ProductVariant;
        foreach (['sale_price' => 'products.update_prices', 'cost_price' => 'products.update_reference_cost'] as $field => $permission) {
            if (array_key_exists($field, $data) && (! $variant->exists || (string) $data[$field] !== (string) $variant->getAttribute($field))) {
                $access->authorize($actor, $permission);
                if ($field === 'cost_price') {
                    $access->authorize($actor, 'products.view_reference_cost');
                }
            }
        }
        foreach (['sku', 'barcode'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }
        if (array_key_exists('barcode', $data) && $data['barcode'] === '') {
            $data['barcode'] = null;
        }
        $rules = ['sku' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('product_variants', 'sku')->ignore($variant->id)], 'barcode' => ['nullable', 'string', 'max:255', Rule::unique('product_variants', 'barcode')->ignore($variant->id)], 'size_id' => ['nullable', 'integer', Rule::exists('sizes', 'id')], 'color_id' => ['nullable', 'integer', Rule::exists('colors', 'id')], 'sale_price' => ['sometimes', 'required', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999999999.9999', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'], 'cost_price' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999999999.9999', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'], 'is_active' => ['sometimes', 'boolean']];
        $data = Validator::make(Arr::only($data, array_keys($rules)), $rules)->validate();
        foreach (['size_id' => Size::class, 'color_id' => Color::class] as $field => $class) {
            if (! empty($data[$field]) && $data[$field] != $variant->getAttribute($field) && ! $class::findOrFail($data[$field])->is_active) {
                $this->invalid($field, 'Choose an active catalog value.');
            }
        }

        return $data;
    }

    public function canDelete(ProductVariant $variant): bool
    {
        return ! app(ProductReferenceChecker::class)->variantIsReferenced($variant)
            && (! $variant->product->is_active || $variant->product->variants()->whereKeyNot($variant->id)->where('is_active', true)->exists());
    }

    public function delete(User $actor, Product $product, ProductVariant $variant): bool
    {
        app(ProductsAccess::class)->authorize($actor, 'products.view');
        app(ProductsAccess::class)->authorize($actor, 'products.variants.delete');

        return DB::transaction(function () use ($product, $variant): bool {
            ModuleRegistryLock::acquire();
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $variant = $product->variants()->whereKey($variant->id)->lockForUpdate()->firstOrFail();
            if (! $this->canDelete($variant)) {
                $this->invalid('record', 'This variant is referenced or is the last active variant.');
            }
            foreach ($variant->images()->get() as $image) {
                app(ProductImageManager::class)->removeRecord($image);
            }

            return (bool) $variant->delete();
        });
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
