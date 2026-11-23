<?php

namespace App\Modules\Inventory\Services;

use App\Filament\RequestDisplayData;
use App\Models\QuantityFormatter;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Inventory\Models\SizeAllocation;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Products\Services\VariantInventoryValuation;
use App\Modules\Purchasing\Models\PurchaseReceiptItem;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class SizeAllocationPresentation
{
    public const LIMIT = 50;

    public function products(?User $actor): Builder
    {
        $access = app(InventoryAccess::class);
        if (! $access->allows($actor, InventoryPermissions::ALLOCATIONS_VIEW)
            || ! $access->allows($actor, InventoryPermissions::ALLOCATIONS_MANAGE)) {
            return Product::query()->whereRaw('1 = 0');
        }

        return Product::whereHas('variants', fn (Builder $query) => $query
            ->whereColumn('unallocated_product_id', 'products.id')->where('is_active', true)
            ->whereNull('size_id')->whereNull('color_id')
            ->whereHas('stockBalances', fn (Builder $query) => $access->scope($query, $actor, InventoryPermissions::ALLOCATIONS_MANAGE)
                ->where('quantity_available', '>', 0)->whereHas('location', fn (Builder $query) => $query->where('is_active', true))));
    }

    public function canReadRecord(?User $actor, ?SizeAllocation $record): bool
    {
        return $record !== null && app(InventoryAccess::class)->allows($actor, InventoryPermissions::ALLOCATIONS_VIEW, (int) $record->location_id)
            && app(InventoryAccess::class)->allows($actor, InventoryPermissions::ALLOCATIONS_MANAGE, (int) $record->location_id);
    }

    public function brands(?User $actor, string $search = '', ?SizeAllocation $record = null): array
    {
        $query = Brand::where(function (Builder $query) use ($actor, $record): void {
            $query->whereIn('id', $this->products($actor)->select('brand_id'));
            if ($this->canReadRecord($actor, $record)) {
                $query->orWhereKey($record->product->brand_id);
            }
        });
        $query->when(trim($search) !== '', fn (Builder $query) => $query->where('name', 'like', '%'.trim($search).'%'))
            ->orderBy('name')->limit(self::LIMIT);
        $unbrandedProducts = $this->products($actor)->whereNull('brand_id');
        $recordHasNoBrand = $this->canReadRecord($actor, $record) && $record->product->brand_id === null;

        return RequestDisplayData::remember('allocation-brand-options:'.json_encode([
            $actor?->id, $query->toSql(), $query->getBindings(), $recordHasNoBrand, app()->getLocale(),
        ], JSON_THROW_ON_ERROR), function () use ($query, $unbrandedProducts, $recordHasNoBrand, $search): array {
            $options = $query->pluck('name', 'id')->all();
            if (($unbrandedProducts->exists() || $recordHasNoBrand)
                && (trim($search) === '' || mb_stripos(__('No brand'), trim($search)) !== false)) {
                $options = ['none' => __('No brand')] + $options;
            }

            return $options;
        });
    }

    public function brandLabel(?User $actor, mixed $value, ?SizeAllocation $record = null): ?string
    {
        if ($value === 'none') {
            return $this->brands($actor)['none'] ?? ($this->canReadRecord($actor, $record) && $record->product->brand_id === null ? __('No brand') : null);
        }
        $brand = Brand::find($value);
        if ($brand === null) {
            return null;
        }

        return $this->products($actor)->where('brand_id', $value)->exists()
            || ($this->canReadRecord($actor, $record) && (string) $record->product->brand_id === (string) $value) ? $brand->name : null;
    }

    public function productOptions(?User $actor, mixed $brandId, string $search = '', ?SizeAllocation $record = null): array
    {
        if (blank($brandId)) {
            return [];
        }
        $query = Product::where(function (Builder $query) use ($actor, $brandId, $record): void {
            $query->whereIn('id', $this->products($actor)->where('brand_id', $brandId === 'none' ? null : $brandId)->select('id'));
            if ($this->canReadRecord($actor, $record) && (string) ($record->product->brand_id ?? 'none') === (string) $brandId) {
                $query->orWhereKey($record->product_id);
            }
        });

        return $query->when(trim($search) !== '', fn (Builder $query) => $query->where('name', 'like', '%'.trim($search).'%'))
            ->orderBy('name')->limit(self::LIMIT)->pluck('name', 'id')->all();
    }

    public function productLabel(?User $actor, mixed $brandId, mixed $value, ?SizeAllocation $record = null): ?string
    {
        $product = Product::find($value);
        if ($product === null || (string) ($product->brand_id ?? 'none') !== (string) $brandId) {
            return null;
        }

        return $this->products($actor)->whereKey($value)->exists()
            || ($this->canReadRecord($actor, $record) && (int) $record->product_id === (int) $value) ? $product->name : null;
    }

    public function locations(?User $actor, mixed $productId, ?SizeAllocation $record = null, string $search = '', mixed $selectedId = null): array
    {
        if (blank($productId)) {
            return [];
        }

        if (! $this->products($actor)->whereKey($productId)->exists() && ! ($this->canReadRecord($actor, $record) && (int) $record->product_id === (int) $productId)) {
            return [];
        }

        return app(InventoryAccess::class)->locations($actor, InventoryPermissions::ALLOCATIONS_MANAGE)
            ->where(function (Builder $query) use ($productId, $record, $actor): void {
                $query->where(fn (Builder $query) => $query->where('is_active', true)->whereIn('id', StockBalance::select('location_id')
                    ->where('quantity_available', '>', 0)->whereHas('variant', fn (Builder $query) => $query->where('unallocated_product_id', $productId)->where('is_active', true))));
                if ($this->canReadRecord($actor, $record)) {
                    $query->orWhereKey($record->location_id);
                }
            })->when(filled($selectedId), fn (Builder $query) => $query->whereKey($selectedId))->when(trim($search) !== '', fn (Builder $query) => $query->where('name', 'like', '%'.trim($search).'%'))->orderBy('name')->limit(self::LIMIT)->pluck('name', 'id')->all();
    }

    public function targets(?User $actor, mixed $productId, mixed $colorId = null, ?SizeAllocation $record = null): Builder
    {
        $query = ProductVariant::where('product_id', $productId)->whereNull('unallocated_product_id')->where('is_active', true)
            ->whereNotNull('size_id')->whereHas('size', fn (Builder $query) => $query->where('is_active', true))
            ->where(fn (Builder $query) => $query->whereNull('color_id')->orWhereHas('color', fn (Builder $query) => $query->where('is_active', true)));
        if (! $this->products($actor)->whereKey($productId)->exists()
            && ! ($this->canReadRecord($actor, $record) && (int) $record->product_id === (int) $productId)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->when(filled($colorId), fn (Builder $query) => $query->where('color_id', $colorId === 'none' ? null : $colorId));
    }

    public function colors(?User $actor, mixed $productId, ?SizeAllocation $record = null): array
    {
        if (blank($productId)) {
            return [];
        }

        if (! $this->products($actor)->whereKey($productId)->exists()
            && ! ($this->canReadRecord($actor, $record) && (int) $record->product_id === (int) $productId)) {
            return [];
        }

        $options = ['none' => view('components.inventory-color', ['name' => __('No color')])->render()];
        foreach (Color::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get() as $color) {
            $options[$color->id] = view('components.inventory-color', ['color' => $color])->render();
        }

        return $options;
    }

    public function sizes(?User $actor, mixed $productId, ?SizeAllocation $record = null): array
    {
        if (blank($productId)) {
            return [];
        }

        if (! $this->products($actor)->whereKey($productId)->exists()
            && ! ($this->canReadRecord($actor, $record) && (int) $record->product_id === (int) $productId)) {
            return [];
        }

        return Size::where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
    }

    public function grid(
        ?User $actor,
        mixed $productId,
        mixed $colorIds = null,
        mixed $sizeIds = null,
        array $currentItems = [],
        ?SizeAllocation $record = null,
    ): array {
        if (blank($productId)) {
            return [];
        }

        $canSelectProduct = $this->products($actor)->whereKey($productId)->exists()
            || ($this->canReadRecord($actor, $record) && (int) $record->product_id === (int) $productId);
        if (! $canSelectProduct) {
            return [];
        }

        $quantities = $this->gridQuantities($currentItems);
        if (is_string($colorIds)) {
            return $this->targets($actor, $productId, $colorIds, $record)->with(['size', 'color'])
                ->orderBy('size_id')->orderBy('color_id')->limit(99)->get()
                ->mapWithKeys(fn (ProductVariant $variant): array => $this->keyGridRow($this->gridRow(
                    $variant->id,
                    $variant->size_id,
                    $variant->color_id,
                    $variant->size->name,
                    $variant->color?->name ?? __('No color'),
                    $quantities[$this->combinationKey($variant->size_id, $variant->color_id)] ?? $quantities['variant:'.$variant->id] ?? '0',
                )))->all();
        }

        if ($colorIds === null && $sizeIds === null) {
            return $this->targets($actor, $productId, record: $record)->with(['size', 'color'])
                ->orderBy('size_id')->orderBy('color_id')->limit(99)->get()
                ->mapWithKeys(fn (ProductVariant $variant): array => $this->keyGridRow($this->gridRow(
                    $variant->id,
                    $variant->size_id,
                    $variant->color_id,
                    $variant->size->name,
                    $variant->color?->name ?? __('No color'),
                    $quantities[$this->combinationKey($variant->size_id, $variant->color_id)] ?? $quantities['variant:'.$variant->id] ?? '0',
                )))->all();
        }

        if (! is_array($colorIds) || ! is_array($sizeIds) || $colorIds === [] || $sizeIds === []) {
            return [];
        }

        $selectedColorIds = array_values(array_unique(array_map(
            fn (mixed $id): ?int => $id === 'none' || $id === null ? null : (is_scalar($id) && ctype_digit((string) $id) ? (int) $id : 0),
            $colorIds,
        )));
        $selectedSizeIds = array_values(array_unique(array_map(
            fn (mixed $id): int => is_scalar($id) && ctype_digit((string) $id) ? (int) $id : 0,
            $sizeIds,
        )));
        if (in_array(0, $selectedColorIds, true) || in_array(0, $selectedSizeIds, true)
            || count($selectedColorIds) * count($selectedSizeIds) > 99) {
            return [];
        }

        $sizes = Size::where('is_active', true)->whereIn('id', $selectedSizeIds)->get()->keyBy('id');
        $colors = Color::where('is_active', true)->whereIn('id', array_filter($selectedColorIds))->get()->keyBy('id');
        if ($sizes->count() !== count($selectedSizeIds)
            || $colors->count() !== count(array_filter($selectedColorIds))) {
            return [];
        }

        $variants = $this->targets($actor, $productId, record: $record)
            ->whereIn('size_id', $selectedSizeIds)
            ->where(function (Builder $query) use ($selectedColorIds): void {
                foreach ($selectedColorIds as $colorId) {
                    if ($colorId === null) {
                        $query->orWhereNull('color_id');
                    } else {
                        $query->orWhere('color_id', $colorId);
                    }
                }
            })->with(['size', 'color'])->get()
            ->keyBy(fn (ProductVariant $variant): string => $this->combinationKey($variant->size_id, $variant->color_id));

        $rows = [];
        foreach ($selectedSizeIds as $sizeId) {
            foreach ($selectedColorIds as $colorId) {
                $key = $this->combinationKey($sizeId, $colorId);
                $variant = $variants->get($key);
                $rows[] = $this->gridRow(
                    $variant?->id,
                    $sizeId,
                    $colorId,
                    $sizes->get($sizeId)->name,
                    $colors->get($colorId)?->name ?? __('No color'),
                    $quantities[$key] ?? '0',
                );
            }
        }

        return collect($rows)->mapWithKeys(fn (array $row): array => $this->keyGridRow($row))->all();
    }

    public function context(?User $actor, array $state, ?SizeAllocation $record = null): array
    {
        $productId = $state['product_id'] ?? null;
        $locationId = $state['location_id'] ?? null;
        $colorIds = array_key_exists('allocation_colors', $state) ? $state['allocation_colors'] : ($state['allocation_color'] ?? null);
        $sizeIds = $state['allocation_sizes'] ?? null;
        $product = $this->productLabel($actor, $state['brand_id'] ?? 'none', $productId, $record) !== null ? Product::find($productId) : null;
        $locations = $product === null ? [] : $this->locations($actor, $productId, $record, selectedId: $locationId);
        $location = $locations[$locationId ?? ''] ?? null;
        $source = $product?->variants()->where('unallocated_product_id', $productId)->where('is_active', true)->whereNull('size_id')->whereNull('color_id')->first();
        $balance = $location === null || $source === null ? null : StockBalance::where('variant_id', $source->id)->where('location_id', $locationId)->first();
        $available = BigDecimal::of($balance?->quantity_available ?? '0');
        $total = BigDecimal::zero();
        $validQuantities = true;
        $rawItems = $state['items'] ?? [];
        $items = is_array($rawItems) ? $rawItems : [];
        $validQuantities = is_array($rawItems);
        $grid = $product === null ? [] : $this->grid($actor, $productId, $colorIds, $sizeIds, $items, $record);
        $gridByCombination = collect($grid)->keyBy(fn (array $row): string => $this->combinationKey($row['size_id'], $row['color_id']));
        $gridByVariant = collect($grid)->filter(fn (array $row): bool => filled($row['variant_id'] ?? null))->keyBy(fn (array $row): string => (string) $row['variant_id']);
        $variantIds = collect($grid)->pluck('variant_id')->filter()->unique()->values()->all();
        $targets = $variantIds === [] ? collect() : $this->targets($actor, $productId, record: $record)->whereKey($variantIds)
            ->with(['size', 'color', 'stockBalances' => fn (HasMany $query) => $query->where('location_id', $locationId)])
            ->get()->keyBy('id');
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                $validQuantities = false;

                continue;
            }
            $quantity = (string) ($item['quantity'] ?? '');
            $id = $item['variant_id'] ?? null;
            $row = isset($item['size_id'])
                ? $gridByCombination->get($this->combinationKey($item['size_id'], $item['color_id'] ?? null))
                : $gridByVariant->get((string) $id);
            $combination = $row === null ? null : $this->combinationKey($row['size_id'], $row['color_id']);
            if (! preg_match('/^\d{1,14}(?:\.0{1,4})?$/D', $quantity) || $row === null
                || (filled($id) && (string) ($row['variant_id'] ?? '') !== (string) $id)
                || in_array($combination, $seen, true)) {
                $validQuantities = false;

                continue;
            }
            $seen[] = $combination;
            $total = $total->plus($quantity);
        }
        $over = $total->isGreaterThan($available);
        $tooManyCombinations = is_array($colorIds) && is_array($sizeIds) && count($colorIds) * count($sizeIds) > 99;
        $valid = $source !== null && $location !== null && $balance !== null && $validQuantities && ! $over
            && ! $tooManyCombinations && $total->isPositive() && count($items) <= 99;
        $image = $product?->images()->where('disk', 'public')->orderByDesc('is_primary')->orderBy('sort_order')->first();
        $cost = null;
        if ($source !== null && $location !== null && app(VariantInventoryValuation::class)->canView($actor)) {
            app(VariantInventoryValuation::class)->authorize($actor, $source, (int) $locationId);
            $cost = InventoryCostState::where('variant_id', $source->id)->where('location_id', $locationId)->first(['avg_cost', 'total_value']);
        }
        $receipt = null;
        if ($source !== null && $location !== null && RequestAuthorizationFacts::tableExists('purchase_receipt_items')
            && app(PurchasingAccess::class)->allows($actor, PurchasingPermissions::RECEIPTS_VIEW, (int) $locationId)) {
            $receipt = PurchaseReceiptItem::whereHas('stockMovement', fn (Builder $query) => $query->where('variant_id', $source->id)->where('location_id', $locationId))
                ->whereHas('purchaseReceipt', fn (Builder $query) => $query->where('location_id', $locationId)->where('status', 'posted'))
                ->with('purchaseReceipt')->orderByDesc('id')->first()?->purchaseReceipt;
        }
        $rows = [];
        foreach ($items as $key => $item) {
            if (! is_array($item)) {
                continue;
            }
            $row = isset($item['size_id'])
                ? $gridByCombination->get($this->combinationKey($item['size_id'], $item['color_id'] ?? null))
                : $gridByVariant->get((string) ($item['variant_id'] ?? ''));
            $variant = $row === null ? null : $targets->get($row['variant_id']);
            $quantity = (string) ($item['quantity'] ?? '');
            $current = $variant?->stockBalances->first();
            $rows[$key] = ['size' => $row['size_label'] ?? __('Unavailable'), 'color' => $row['color_label'] ?? __('No color'),
                'available' => QuantityFormatter::format($current?->quantity_available ?? '0'),
                'after' => preg_match('/^\d{1,14}(?:\.0{1,4})?$/D', $quantity) ? QuantityFormatter::format((string) BigDecimal::of($current?->quantity_on_hand ?? '0')->plus($quantity)) : null];
        }
        $colorLabels = collect($grid)->pluck('color_label')->unique()->implode(', ');
        if ($colorLabels === '') {
            $colorLabels = __('All colors');
        }

        return ['brand_selected' => filled($state['brand_id'] ?? null), 'product_selected' => $product !== null, 'source_selected' => $balance !== null, 'record_status' => $record?->status ?? 'new', 'product' => $product, 'source' => $source, 'location' => $location, 'color' => $colorLabels,
            'image' => $image ? Storage::disk('public')->url($image->path) : asset('images/product-placeholder.svg'),
            'available' => QuantityFormatter::format((string) $available), 'total' => QuantityFormatter::format((string) $total),
            'remaining' => QuantityFormatter::format((string) $available->minus($total)), 'is_valid' => $valid, 'over_allocated' => $over,
            'valid_quantities' => $validQuantities, 'too_many_combinations' => $tooManyCombinations,
            'has_context' => $balance !== null, 'cost' => $cost, 'receipt' => $receipt, 'rows' => $rows];
    }

    private function gridQuantities(array $items): array
    {
        $quantities = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (isset($item['size_id'])) {
                $quantities[$this->combinationKey($item['size_id'], $item['color_id'] ?? null)] = (string) ($item['quantity'] ?? '0');
            } elseif (filled($item['variant_id'] ?? null)) {
                $quantities['variant:'.$item['variant_id']] = (string) ($item['quantity'] ?? '0');
            }
        }

        return $quantities;
    }

    private function gridRow(
        mixed $variantId,
        mixed $sizeId,
        mixed $colorId,
        string $sizeLabel,
        string $colorLabel,
        string $quantity,
    ): array {
        return ['variant_id' => $variantId, 'size_id' => $sizeId, 'color_id' => $colorId, 'quantity' => $quantity,
            'size_label' => $sizeLabel, 'color_label' => $colorLabel];
    }

    private function keyGridRow(array $row): array
    {
        $identity = filled($row['variant_id'])
            ? 'variant-'.$row['variant_id']
            : 'combination-'.$row['size_id'].'-color-'.($row['color_id'] ?? 'none');

        return [$identity => $row];
    }

    private function combinationKey(mixed $sizeId, mixed $colorId): string
    {
        return (string) $sizeId.':'.($colorId === null || $colorId === '' || $colorId === 'none' ? 'none' : (string) $colorId);
    }
}
