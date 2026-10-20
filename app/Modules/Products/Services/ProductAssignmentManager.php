<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Discounts\Models\Discount;
use App\Modules\Discounts\Services\DiscountManager;
use App\Modules\Discounts\Services\DiscountReferenceChecker;
use App\Modules\Products\Models\Product;
use App\Modules\Taxes\Models\Tax;
use App\Modules\Taxes\Services\DefinitionAccess;
use App\Modules\Taxes\Services\TaxManager;
use App\Modules\Taxes\Services\TaxReferenceChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductAssignmentManager
{
    public function canAssign(?User $actor, string $kind): bool
    {
        return $this->available($kind)
            && app(ProductsAccess::class)->allows($actor, 'products.view')
            && app(ProductsAccess::class)->allows($actor, 'products.assign_'.$kind)
            && app(DefinitionAccess::class)->allows($actor, $kind, 'assign');
    }

    public function options(User $actor, string $kind, ?Product $product = null): array
    {
        if (! $this->canAssign($actor, $kind)) {
            return [];
        }
        $existing = $product?->{$kind}()->pluck($kind.'.id')->all() ?? [];
        $start = $kind === 'taxes' ? 'effective_from' : 'starts_at';
        $end = $kind === 'taxes' ? 'effective_to' : 'ends_at';
        $now = $kind === 'taxes' ? now()->toDateString() : now()->toDateTimeString();

        return DB::table($kind)->where(function ($query) use ($kind, $existing, $start, $end, $now): void {
            $query->where(function ($query) use ($kind, $start, $end, $now): void {
                $query->where('is_active', true)
                    ->where(fn ($query) => $query->whereNull($start)->when($kind === 'taxes', fn ($query) => $query->orWhereDate($start, '<=', $now), fn ($query) => $query->orWhere($start, '<=', $now)))
                    ->where(fn ($query) => $query->whereNull($end)->when($kind === 'taxes', fn ($query) => $query->orWhereDate($end, '>=', $now), fn ($query) => $query->orWhere($end, '>=', $now)))
                    ->when($kind === 'discounts', fn ($query) => $query->where('scope', 'product'));
            })->orWhereIn('id', $existing);
        })->orderBy('name')->pluck('name', 'id')->all();
    }

    public function createDefinition(User $actor, string $kind, array $data): int
    {
        return DB::transaction(function () use ($actor, $kind, $data): int {
            ModuleRegistryLock::acquire();
            abort_unless($this->canAssign($actor, $kind), 403);
            if ($kind === 'discounts' && ($data['scope'] ?? 'product') !== 'product') {
                throw ValidationException::withMessages(['scope' => __('Choose active, currently applicable product definitions.')]);
            }
            $manager = $kind === 'taxes' ? TaxManager::class : DiscountManager::class;

            return app($manager)->save($actor, $data)->getKey();
        });
    }

    public function available(string $kind): bool
    {
        abort_unless(in_array($kind, ['taxes', 'discounts'], true), 404);

        return Schema::hasTable($kind) && Schema::hasTable($kind === 'taxes' ? 'product_taxes' : 'discount_products') && app(ModuleAccess::class)->isActive($kind);
    }

    public function validateSelection(User $actor, string $kind, array $ids, ?Product $product = null): void
    {
        abort_unless($this->canAssign($actor, $kind), 403);
        Validator::make(['ids' => $ids], ['ids' => ['array'], 'ids.*' => ['integer', 'distinct', 'exists:'.$kind.',id']])->validate();
        $existing = $product?->{$kind}()->pluck($kind.'.id')->all() ?? [];
        $definitions = DB::table($kind)->whereIn('id', array_unique([...$ids, ...$existing]))->lockForUpdate()->get()->keyBy('id');
        foreach (array_diff($existing, $ids) as $id) {
            $model = $kind === 'taxes' ? Tax::class : Discount::class;
            $checker = $kind === 'taxes' ? TaxReferenceChecker::class : DiscountReferenceChecker::class;
            if (app($checker)->hasHistoricalReferences($model::findOrFail($id))) {
                throw ValidationException::withMessages(['ids' => __('Historical product assignments cannot be removed. Deactivate the definition instead.')]);
            }
        }
        foreach (array_diff($ids, $existing) as $id) {
            $definition = $definitions->get($id);
            $now = $kind === 'taxes' ? now()->toDateString() : now()->toDateTimeString();
            $start = $kind === 'taxes' ? $definition?->effective_from : $definition?->starts_at;
            $end = $kind === 'taxes' ? $definition?->effective_to : $definition?->ends_at;
            if ($kind === 'taxes') {
                $start = $start === null ? null : substr($start, 0, 10);
                $end = $end === null ? null : substr($end, 0, 10);
            }
            if ($definition === null || ! $definition->is_active || ($start !== null && $start > $now) || ($end !== null && $end < $now) || ($kind === 'discounts' && $definition->scope !== 'product')) {
                throw ValidationException::withMessages(['ids' => __('Choose active, currently applicable product definitions.')]);
            }
        }
    }

    public function sync(User $actor, Product $product, string $kind, array $ids): void
    {
        DB::transaction(function () use ($actor, $product, $kind, $ids): void {
            ModuleRegistryLock::acquire();
            abort_unless($this->canAssign($actor, $kind), 403);
            abort_unless($product->exists && $product->getConnection()->getName() === (new Product)->getConnection()->getName(), 403);
            $product = Product::whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $this->validateSelection($actor, $kind, $ids, $product);
            $product->{$kind}()->sync($ids);
        });
    }
}
