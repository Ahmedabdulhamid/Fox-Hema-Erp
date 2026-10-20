<?php

namespace App\Modules\InventoryCosting\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Services\ProductReferenceChecker;
use App\Modules\Products\Services\ProductsAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoryCostingManager
{
    public const METHODS = ['standard' => 'Standard cost', 'avco' => 'Average cost (AVCO)', 'fifo' => 'First in, first out (FIFO)'];

    public function available(): bool
    {
        return app(ModuleAccess::class)->isActive('inventory_costing') && Schema::hasTable('inventory_valuation_settings');
    }

    public function canView(?User $actor): bool
    {
        return $this->available() && app(ProductsAccess::class)->allows($actor, 'products.categories.view') && app(ProductsAccess::class)->allows($actor, 'products.categories.view_costing');
    }

    public function canUpdate(?User $actor): bool
    {
        return $this->canView($actor) && app(ProductsAccess::class)->allows($actor, 'products.categories.update_costing');
    }

    public function save(User $actor, Category $category, array $data): ?InventoryValuationSetting
    {
        app(ProductsAccess::class)->authorize($actor, 'products.categories.view');
        app(ProductsAccess::class)->authorize($actor, 'products.categories.view_costing');
        app(ProductsAccess::class)->authorize($actor, 'products.categories.update_costing');
        abort_unless($this->available(), 403);

        return DB::transaction(function () use ($category, $data): ?InventoryValuationSetting {
            ModuleRegistryLock::acquire();
            abort_unless($this->available(), 403);
            $category = Category::whereKey($category->id)->lockForUpdate()->firstOrFail();
            $setting = $category->inventoryValuationSetting()->lockForUpdate()->first() ?? new InventoryValuationSetting(['category_id' => $category->id]);
            if (! array_key_exists('costing_method', $data)) {
                $data['costing_method'] = $setting->costing_method;
            }
            if ($data['costing_method'] === '') {
                $data['costing_method'] = null;
            }
            $values = Validator::make(['inventory_costing' => $data], [
                'inventory_costing.costing_method' => ['nullable', Rule::requiredIf($setting->exists || filled($data['note'] ?? null)), Rule::in(array_keys(self::METHODS))],
                'inventory_costing.note' => ['nullable', 'string', 'max:5000'],
            ])->validate()['inventory_costing'];
            if ($values['costing_method'] === null) {
                return null;
            }
            if ($setting->costing_method !== $values['costing_method']) {
                if (app(ProductReferenceChecker::class)->categoryIsReferenced($category)) {
                    throw ValidationException::withMessages(['inventory_costing.costing_method' => __('The costing method is locked after products have inventory or business transactions.')]);
                }
            }
            $setting->fill($values);
            if (! $setting->exists || $setting->isDirty()) {
                $setting->save();
            }

            return $setting;
        });
    }
}
