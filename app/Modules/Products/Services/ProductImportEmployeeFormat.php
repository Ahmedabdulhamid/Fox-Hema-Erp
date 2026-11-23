<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Products\Models\ProductImport;
use Illuminate\Validation\ValidationException;

class ProductImportEmployeeFormat
{
    public const VERSION = 'products-v2';

    public const UNALLOCATED_PREFIX = 'product-export-unallocated:';

    public const COLUMNS = [
        'Products' => ['name', 'description', 'brand', 'category', 'status', 'pricing_mode', 'shared_sale_price'],
        'Variants' => ['product_name', 'color', 'size', 'sale_price', 'reference_cost', 'variant_status'],
        'ProductTaxes' => ['product_name', 'tax'],
        'ProductDiscounts' => ['product_name', 'discount'],
        'Images' => ['product_name', 'color', 'size', 'upload_name', 'is_primary', 'sort_order', 'alt_text', 'image_scope'],
    ];

    public function headers(string $sheet, string $locale): array
    {
        return array_map(fn (string $field): string => __('product_import.employee_columns.'.$field, [], $locale), self::COLUMNS[$sheet]);
    }

    /** Convert presentation fields only; reference resolution belongs to bounded per-product validation. */
    public function row(ProductImport $import, string $sheet, array $values, string $locale, int $number, array $bindings = []): array
    {
        $raw = [];
        foreach (self::COLUMNS[$sheet] as $index => $field) {
            $value = $values[$index] ?? '';
            if (is_float($value)) {
                $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            }
            $raw[$field] = trim((string) $value);
        }
        $name = $raw[$sheet === 'Products' ? 'name' : 'product_name'];
        if (app(ProductImportProductIdentifiers::class)->normalizeName($name) === '') {
            throw ValidationException::withMessages([$sheet.':'.$number.':name' => __('product_import.employee_missing_product', ['row' => $number])]);
        }
        $data = array_fill_keys(ProductImportWorkbook::HEADERS[$sheet], '');
        $data['product_key'] = app(ProductImportProductIdentifiers::class)->productKey($import, $name);
        $data['_employee'] = ['locale' => $locale, 'name' => $name, 'raw' => $raw, 'references' => []];
        $references = match ($sheet) {
            'Products' => ['brand' => ['brands', 'brand_uuid'], 'category' => ['categories', 'category_uuid']],
            'Variants', 'Images' => ['color' => ['colors', 'color_uuid'], 'size' => ['sizes', 'size_uuid']],
            'ProductTaxes' => ['tax' => ['taxes', 'tax_uuid']],
            'ProductDiscounts' => ['discount' => ['discounts', 'discount_uuid']],
        };
        foreach ($references as $field => [$kind, $column]) {
            if ($raw[$field] !== '') {
                $key = app(ProductImportProductIdentifiers::class)->normalizeName($raw[$field]);
                $uuid = $bindings[$kind][$key] ?? null;
                if ($uuid === null) {
                    $data['_employee_errors'][$column] = __('product_import.employee_reference_missing', ['value' => $raw[$field], 'kind' => __('product_import.employee_kinds.'.$kind)]);
                } else {
                    $data['_employee']['references'][$column] = ['kind' => $kind, 'value' => $raw[$field], 'uuid' => $uuid];
                }
            } elseif (in_array($sheet, ['ProductTaxes', 'ProductDiscounts'], true)) {
                $data['_employee_errors'][$column] = __('product_import.employee_reference_required', ['kind' => __('product_import.employee_kinds.'.$kind)]);
            }
        }
        foreach (['shared_sale_price', 'sale_price', 'reference_cost', 'upload_name', 'alt_text', 'sort_order', 'description', 'name'] as $field) {
            if (array_key_exists($field, $raw)) {
                $data[$field] = $raw[$field];
            }
        }
        foreach (['shared_sale_price', 'sale_price', 'reference_cost', 'sort_order'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = strtr($data[$field], ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.']);
            }
        }
        $enum = function (string $field, string $group, string $default) use (&$data, $raw): string {
            $value = $raw[$field];
            if ($value === '') {
                return $default;
            }
            foreach (['ar', 'en'] as $language) {
                foreach (__('product_import.employee_values.'.$group, [], $language) as $canonical => $label) {
                    if (app(ProductImportProductIdentifiers::class)->normalizeName($label) === app(ProductImportProductIdentifiers::class)->normalizeName($value)) {
                        return (string) $canonical;
                    }
                }
            }
            $data['_employee_errors'][$field] = __('product_import.employee_dropdown_value', ['value' => $value]);

            return $default;
        };
        if ($sheet === 'Products') {
            $data['status'] = $enum('status', 'status', 'draft');
            $data['has_fixed_selling_price'] = $enum('pricing_mode', 'pricing', '0');
        }
        if ($sheet === 'Variants') {
            $data['is_active'] = $enum('variant_status', 'active', '1');
            $data['variant_key'] = app(ProductImportProductIdentifiers::class)->variantKey($data['product_key'], $raw['color'], $raw['size']);
        }
        if ($sheet === 'Images') {
            $scope = $enum('image_scope', 'scope', ($raw['color'] === '' && $raw['size'] === '') ? 'product' : 'variant');
            $data['_employee']['scope'] = $scope;
            $data['is_primary'] = $enum('is_primary', 'boolean', '0');
            $data['image_key'] = 'i-'.substr(hash('sha256', json_encode([$data['product_key'], $raw['color'], $raw['size'], $scope, $raw['upload_name']], JSON_THROW_ON_ERROR)), 0, 48);
        }

        return $data;
    }

    /** Resolve once into the immutable payload; later calls require the same UUID and current eligibility. */
    public function resolve(User $actor, array $payload): array
    {
        if (! isset($payload['Products'][0]['data']['_employee'])) {
            return $payload;
        }
        $resolver = app(ProductImportReferenceResolver::class);
        foreach (self::COLUMNS as $sheet => $columns) {
            foreach ($payload[$sheet] ?? [] as $index => $row) {
                $data = $row['data'];
                foreach ($data['_employee_errors'] ?? [] as $field => $message) {
                    throw ValidationException::withMessages([$sheet.':'.$row['row_number'].':'.$field => $message]);
                }
                foreach ($data['_employee']['references'] as $field => $reference) {
                    try {
                        $uuid = $resolver->resolve($actor, $reference['kind'], $reference['value'], $field, $reference['uuid'] ?? null);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages([$sheet.':'.$row['row_number'].':'.$field => $exception->errors()[$field]]);
                    }
                    $data[$field] = $uuid;
                    $data['_employee']['references'][$field]['uuid'] = $uuid;
                }
                if ($sheet === 'Variants' || $sheet === 'Images') {
                    $variantKey = app(ProductImportProductIdentifiers::class)->variantKey($data['product_key'], $data['color_uuid'] ?? '', $data['size_uuid'] ?? '');
                    if ($sheet === 'Images') {
                        if ($data['_employee']['scope'] === 'product' && (($data['color_uuid'] ?? '') !== '' || ($data['size_uuid'] ?? '') !== '')) {
                            throw ValidationException::withMessages(['Images:'.$row['row_number'].':image_scope' => __('product_import.employee_image_scope')]);
                        }
                        $data['variant_key'] = $data['_employee']['scope'] === 'product' ? '' : $variantKey;
                    } else {
                        $data['variant_key'] = $variantKey;
                    }
                }
                $payload[$sheet][$index]['data'] = $data;
            }
        }

        return $payload;
    }

    public function errorLabel(string $field): string
    {
        $parts = explode(':', $field, 3);
        if (count($parts) !== 3) {
            $label = __('product_import.'.$field);

            return $label === 'product_import.'.$field ? __('product_import.results') : $label;
        }
        [$sheet, $row, $column] = $parts;
        $column = ['brand_uuid' => 'brand', 'category_uuid' => 'category', 'color_uuid' => 'color', 'size_uuid' => 'size', 'tax_uuid' => 'tax', 'discount_uuid' => 'discount', 'product_key' => 'product_name', 'variant_key' => 'variant_target', 'image_key' => 'upload_name', 'has_fixed_selling_price' => 'pricing_mode', 'is_active' => 'variant_status'][$column] ?? $column;

        $label = __('product_import.employee_columns.'.$column);
        if ($label === 'product_import.employee_columns.'.$column) {
            $label = __('product_import.results');
        }

        return __('product_import.employee_sheets.'.$sheet).' · '.__('product_import.employee_row', ['row' => $row]).' · '.$label;
    }
}
