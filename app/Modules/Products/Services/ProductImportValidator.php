<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Discounts\Models\Discount;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImport;
use App\Modules\Products\Models\Size;
use App\Modules\Taxes\Models\Tax;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductImportValidator
{
    /** @return array{data: array, variants: array, images: array, warnings: array, resolved_payload: array} */
    public function validate(User $actor, ProductImport $import, array $payload, bool $decodeImages = false): array
    {
        app(ProductImportAccess::class)->authorize($actor, $import);
        $payload = app(ProductImportEmployeeFormat::class)->resolve($actor, $payload);
        $parent = $payload['Products'][0] ?? null;
        if ($parent === null || count($payload['Products']) !== 1) {
            throw ValidationException::withMessages(['workbook' => __('product_import.duplicate_product')]);
        }
        $values = $parent['data'];
        $data = $this->at($parent, function () use ($actor, $values): array {
            Validator::make($values, [
                'product_key' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/D'],
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:10000'],
                'status' => ['nullable', 'in:draft,active,archived'],
                'has_fixed_selling_price' => ['nullable', 'in:0,1'],
            ])->validate();
            if (Product::where('default_sku', $values['product_key'])->exists()) {
                throw ValidationException::withMessages(['product_key' => __('product_import.existing_product')]);
            }
            $fixed = $values['has_fixed_selling_price'] === '1';
            $data = ['name' => $values['name'], 'description' => $values['description'] ?: null, 'default_sku' => $values['product_key'], 'status' => $values['status'] ?: 'draft'];
            if ($fixed) {
                app(ProductsAccess::class)->authorize($actor, 'products.update_prices');
                $data['has_fixed_selling_price'] = true;
                $data['sale_price'] = $this->decimal($values['shared_sale_price'], 'shared_sale_price');
            } elseif ($values['shared_sale_price'] !== '') {
                throw ValidationException::withMessages(['shared_sale_price' => __('product_import.fixed_price_rule')]);
            }
            $data['brand_id'] = $this->lookup(Brand::class, $values['brand_uuid'], 'brand_uuid');
            $data['category_id'] = $this->lookup(Category::class, $values['category_uuid'], 'category_uuid');

            return $data;
        });
        $variants = [];
        $pairs = [];
        $variantRows = $payload['Variants'] ?? [];
        if (count($variantRows) > config('product-import.variants_per_product')) {
            throw ValidationException::withMessages(['variants' => __('product_import.row_limit')]);
        }
        foreach ($variantRows as $row) {
            $values = $row['data'];
            $key = strtolower($values['variant_key']);
            $variants[$key] = $this->at($row, function () use ($actor, $values, $key, $variants, $data, &$pairs): array {
                Validator::make($values, ['variant_key' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D'], 'is_active' => ['nullable', 'in:0,1']])->validate();
                if (isset($variants[$key])) {
                    throw ValidationException::withMessages(['variant_key' => __(isset($values['_employee']) ? 'product_import.employee_duplicate_variant' : 'product_import.duplicate_variant')]);
                }
                $variant = [
                    'size_id' => $this->lookup(Size::class, $values['size_uuid'], 'size_uuid'),
                    'color_id' => $this->lookup(Color::class, $values['color_uuid'], 'color_uuid'),
                    'is_active' => $values['is_active'] !== '0',
                ];
                $pair = ($variant['size_id'] ?? 'none').':'.($variant['color_id'] ?? 'none');
                if (isset($pairs[$pair])) {
                    throw ValidationException::withMessages(['variant_key' => __(isset($values['_employee']) ? 'product_import.employee_duplicate_variant' : 'product_import.duplicate_variant')]);
                }
                $pairs[$pair] = true;
                if ($data['has_fixed_selling_price'] ?? false) {
                    if ($values['sale_price'] !== '') {
                        throw ValidationException::withMessages(['sale_price' => __('product_import.fixed_price_rule')]);
                    }
                } else {
                    $variant['sale_price'] = $this->decimal($values['sale_price'], 'sale_price');
                }
                if ($values['reference_cost'] !== '') {
                    $variant['cost_price'] = $this->decimal($values['reference_cost'], 'reference_cost');
                }

                $validated = app(ProductVariantManager::class)->validateData($actor, $variant);
                if (($values['_export_unallocated'] ?? false) && $variant['size_id'] === null && $variant['color_id'] === null) {
                    $validated['_export_unallocated'] = true;
                }

                return $validated;
            });
        }
        $hasUnallocated = collect($variants)->contains(fn (array $variant): bool => $variant['_export_unallocated'] ?? false);
        if ((isset($pairs['none:none']) && count($pairs) > 1 && ! $hasUnallocated) || ($data['status'] === 'active' && ! collect($variants)->contains(fn (array $variant): bool => $variant['is_active']))) {
            throw ValidationException::withMessages(['variants' => __('product_import.variant_rule')]);
        }
        foreach (['ProductTaxes' => ['taxes', Tax::class, 'tax_uuid', 'tax_ids'], 'ProductDiscounts' => ['discounts', Discount::class, 'discount_uuid', 'discount_ids']] as $sheet => [$kind, $class, $column, $field]) {
            $rows = $payload[$sheet] ?? [];
            if (count($rows) > 20) {
                throw ValidationException::withMessages([$field => __('product_import.row_limit')]);
            }
            $ids = [];
            foreach ($rows as $row) {
                $ids[] = $this->at($row, fn (): int => $this->lookup($class, $row['data'][$column], $column, required: true));
            }
            if ($rows !== []) {
                $this->at($rows[0], function () use ($actor, $kind, $ids): void {
                    app(ProductAssignmentManager::class)->validateSelection($actor, $kind, $ids);
                });
                $data[$field] = $ids;
            }
        }
        $images = [];
        $primary = [];
        if (count($payload['Images'] ?? []) > config('product-import.images_per_product')) {
            throw ValidationException::withMessages(['images' => __('product_import.row_limit')]);
        }
        foreach ($payload['Images'] ?? [] as $row) {
            $images[] = $this->at($row, function () use ($actor, $import, $row, $variants, $decodeImages, &$primary): array {
                app(ProductsAccess::class)->authorize($actor, 'products.images.create');
                $values = $row['data'];
                Validator::make($values, [
                    'image_key' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D'],
                    'upload_name' => ['required', 'string', 'max:255'],
                    'alt_text' => ['nullable', 'string', 'max:255'],
                    'sort_order' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
                    'is_primary' => ['nullable', 'in:0,1'],
                ])->validate();
                $key = strtolower($values['variant_key']);
                if ($key !== '' && ! isset($variants[$key])) {
                    throw ValidationException::withMessages(['variant_key' => __(isset($values['_employee']) ? 'product_import.employee_missing_variant' : 'product_import.missing_reference')]);
                }
                $asset = $import->assets()->where('upload_name', $values['upload_name'])->first();
                if ($asset === null || $asset->upload_name !== $values['upload_name']) {
                    throw ValidationException::withMessages(['upload_name' => __('product_import.missing_image')]);
                }
                if ($values['is_primary'] === '1') {
                    if (isset($primary[$key])) {
                        throw ValidationException::withMessages(['is_primary' => __('product_import.primary_conflict')]);
                    }
                    $primary[$key] = true;
                }
                $upload = app(ProductImportStorage::class)->asset($import, $asset);
                if ($decodeImages) {
                    app(ProductImportStorage::class)->validateImage($upload);
                }

                return ['asset_id' => $asset->id, 'variant_key' => $key, 'image_key' => $values['image_key'], 'alt_text' => $values['alt_text'], 'sort_order' => (int) ($values['sort_order'] ?: 0), 'is_primary' => $values['is_primary'] === '1'];
            });
        }
        $warnings = [];
        if (empty($data['category_id'])) {
            $warnings[] = __('product_import.costing_warning');
        }
        if (count($data['discount_ids'] ?? []) > 1) {
            $warnings[] = __('product_import.discount_warning');
        }

        return [...compact('data', 'variants', 'images', 'warnings'), 'resolved_payload' => $payload];
    }

    private function decimal(string $value, string $field): string
    {
        if (! preg_match('/^\d{1,14}(?:\.\d{1,4})?$/D', $value)) {
            throw ValidationException::withMessages([$field => __('product_import.invalid_decimal')]);
        }

        return $value;
    }

    private function lookup(string $class, string $uuid, string $field, bool $required = false): ?int
    {
        if ($uuid === '' && ! $required) {
            return null;
        }
        if (! Str::isUuid($uuid)) {
            throw ValidationException::withMessages([$field => __('product_import.missing_reference')]);
        }
        $record = $class::where('uuid', $uuid)->where('is_active', true)->first();
        if ($record === null) {
            throw ValidationException::withMessages([$field => __('product_import.missing_reference')]);
        }

        return (int) $record->id;
    }

    private function at(array $row, Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$row['sheet'].':'.$row['row_number'].':'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }
}
