<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Products\Models\Product;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductCreationManager
{
    public function create(User $actor, array $data): Product
    {
        $access = app(ProductsAccess::class);
        $access->authorize($actor, 'products.view');
        $access->authorize($actor, 'products.create');
        $imagesManager = app(ProductImageManager::class);

        return DB::transaction(function () use ($actor, $data, $access, $imagesManager): Product {
            ModuleRegistryLock::acquire();
            Validator::make($data, [
                'variants' => ['sometimes', 'array'], 'variants.*' => ['array'],
                'images' => ['sometimes', 'array'], 'images.*' => ['array'],
                'variants.*.sku' => ['prohibited'], 'variants.*.barcode' => ['prohibited'],
                'tax_ids' => ['sometimes', 'array'], 'discount_ids' => ['sometimes', 'array'],
                'simple_variant' => ['prohibited'],
            ])->validate();
            $variants = Arr::pull($data, 'variants', []);
            $images = Arr::pull($data, 'images', []);
            $assignments = [];
            foreach (['taxes' => 'tax_ids', 'discounts' => 'discount_ids'] as $kind => $field) {
                if (array_key_exists($field, $data)) {
                    $ids = Arr::pull($data, $field);
                    $this->validateAt($field, fn () => app(ProductAssignmentManager::class)->validateSelection($actor, $kind, $ids));
                    $assignments[$kind] = $ids;
                }
            }
            foreach ($variants as $key => $values) {
                abort_if(array_key_exists('product_id', $values), 403);
                $values = Arr::except($values, ['sku', 'barcode']);
                $variants[$key] = $this->validateAt('variants.'.$key, fn () => app(ProductVariantManager::class)->validateData($actor, $values));
            }
            $combinations = [];
            $hasSimple = false;
            foreach ($variants as $key => $values) {
                $size = $values['size_id'] ?? null;
                $color = $values['color_id'] ?? null;
                $combination = ($size ?? 'none').':'.($color ?? 'none');
                $hasSimple = $hasSimple || ($size === null && $color === null);
                if (isset($combinations[$combination]) || ($hasSimple && count($variants) > 1)) {
                    throw ValidationException::withMessages(['variants.'.$key.'.size_id' => __('Duplicate combinations and mixing simple/variable variants are not allowed.')]);
                }
                $combinations[$combination] = true;
            }
            foreach ($images as $key => $values) {
                $access->authorize($actor, 'products.images.create');
                abort_if(Arr::hasAny($values, ['path', 'disk', 'product_id', 'product_variant_id', 'variant_key']), 403);
                $images[$key] = $this->validateAt('images.'.$key, fn () => $imagesManager->validateData($values));
            }
            $product = app(ProductCatalogManager::class)->save($actor, Product::class, $data);
            foreach ($variants as $key => $values) {
                $this->validateAt('variants.'.$key, fn () => app(ProductVariantManager::class)->save($actor, $product, $values));
            }
            foreach ($assignments as $kind => $ids) {
                $this->validateAt($kind === 'taxes' ? 'tax_ids' : 'discount_ids', fn () => app(ProductAssignmentManager::class)->sync($actor, $product, $kind, $ids));
            }
            foreach ($images as $key => $values) {
                $this->validateAt('images.'.$key, fn () => $imagesManager->save($actor, $product, $values));
            }

            return $product;
        });
    }

    private function validateAt(string $prefix, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(
                fn (array $messages, string $field): array => [$prefix.(in_array($prefix, ['tax_ids', 'discount_ids'], true) ? '' : '.'.$field) => $messages],
            )->all());
        }
    }
}
