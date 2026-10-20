<?php

namespace App\Modules\Inventory\Services;

use App\Models\QuantityFormatter;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Models\SizeAllocation;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\VariantInventoryValuation;
use App\Modules\Purchasing\Models\PurchaseReceiptItem;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
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
        $options = $query->when(trim($search) !== '', fn (Builder $query) => $query->where('name', 'like', '%'.trim($search).'%'))
            ->orderBy('name')->limit(self::LIMIT)->pluck('name', 'id')->all();
        if (($this->products($actor)->whereNull('brand_id')->exists()
            || ($this->canReadRecord($actor, $record) && $record->product->brand_id === null))
            && (trim($search) === '' || mb_stripos(__('No brand'), trim($search)) !== false)) {
            $options = ['none' => __('No brand')] + $options;
        }

        return $options;
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
            ->whereNotNull('size_id')->whereHas('size', fn (Builder $query) => $query->where('is_active', true));
        if (! $this->products($actor)->whereKey($productId)->exists()
            && ! ($this->canReadRecord($actor, $record) && (int) $record->product_id === (int) $productId)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->when(filled($colorId), fn (Builder $query) => $query->where('color_id', $colorId === 'none' ? null : $colorId));
    }

    public function colors(?User $actor, mixed $productId, ?SizeAllocation $record = null): array
    {
        $targets = $this->targets($actor, $productId, record: $record);
        $options = Color::whereIn('id', (clone $targets)->select('color_id'))->orderBy('sort_order')->limit(self::LIMIT)->pluck('name', 'id')->all();
        if ((clone $targets)->whereNull('color_id')->exists()) {
            $options = ['none' => __('No color')] + $options;
        }

        return $options;
    }

    public function grid(?User $actor, mixed $productId, mixed $colorId = null, ?SizeAllocation $record = null): array
    {
        return $this->targets($actor, $productId, $colorId, $record)->with(['size', 'color'])->orderBy('size_id')->orderBy('color_id')->limit(99)->get()
            ->map(fn (ProductVariant $variant): array => ['variant_id' => $variant->id, 'quantity' => '0',
                'size_label' => $variant->size->name, 'color_label' => $variant->color?->name ?? __('No color')])->all();
    }

    public function context(?User $actor, array $state, ?SizeAllocation $record = null): array
    {
        $productId = $state['product_id'] ?? null;
        $locationId = $state['location_id'] ?? null;
        $colorId = $state['allocation_color'] ?? null;
        $product = $this->productLabel($actor, $state['brand_id'] ?? 'none', $productId, $record) !== null ? Product::find($productId) : null;
        $locations = $product === null ? [] : $this->locations($actor, $productId, $record, selectedId: $locationId);
        $location = $locations[$locationId ?? ''] ?? null;
        $source = $product?->variants()->where('unallocated_product_id', $productId)->where('is_active', true)->whereNull('size_id')->whereNull('color_id')->first();
        $balance = $location === null || $source === null ? null : StockBalance::where('variant_id', $source->id)->where('location_id', $locationId)->first();
        $available = BigDecimal::of($balance?->quantity_available ?? '0');
        $total = BigDecimal::zero();
        $validQuantities = true;
        $items = $state['items'] ?? [];
        $targets = $product === null ? collect() : $this->targets($actor, $productId, $colorId, $record)->with(['size', 'color'])
            ->with(['stockBalances' => fn (HasMany $query) => $query->where('location_id', $locationId)])->orderBy('size_id')->limit(99)->get()->keyBy('id');
        $seen = [];
        foreach ($items as $item) {
            $quantity = (string) ($item['quantity'] ?? '');
            $id = $item['variant_id'] ?? null;
            if (! preg_match('/^\d{1,14}(?:\.0{1,4})?$/D', $quantity) || ! $targets->has($id) || in_array($id, $seen)) {
                $validQuantities = false;

                continue;
            }
            $seen[] = $id;
            $total = $total->plus($quantity);
        }
        $over = $total->isGreaterThan($available);
        $valid = $source !== null && $location !== null && $balance !== null && $validQuantities && ! $over && $total->isPositive() && count($items) <= 99;
        $image = $product?->images()->where('disk', 'public')->orderByDesc('is_primary')->orderBy('sort_order')->first();
        $cost = null;
        if ($source !== null && $location !== null && app(VariantInventoryValuation::class)->canView($actor)) {
            app(VariantInventoryValuation::class)->authorize($actor, $source, (int) $locationId);
            $cost = InventoryCostState::where('variant_id', $source->id)->where('location_id', $locationId)->first(['avg_cost', 'total_value']);
        }
        $receipt = null;
        if ($source !== null && $location !== null && Schema::hasTable('purchase_receipt_items')
            && app(PurchasingAccess::class)->allows($actor, PurchasingPermissions::RECEIPTS_VIEW, (int) $locationId)) {
            $receipt = PurchaseReceiptItem::whereHas('stockMovement', fn (Builder $query) => $query->where('variant_id', $source->id)->where('location_id', $locationId))
                ->whereHas('purchaseReceipt', fn (Builder $query) => $query->where('location_id', $locationId)->where('status', 'posted'))
                ->with('purchaseReceipt')->orderByDesc('id')->first()?->purchaseReceipt;
        }
        $rows = [];
        foreach ($items as $key => $item) {
            $variant = $targets->get($item['variant_id'] ?? null);
            $quantity = (string) ($item['quantity'] ?? '');
            $current = $variant?->stockBalances->first();
            $rows[$key] = ['size' => $variant?->size?->name ?? __('Unavailable'), 'color' => $variant?->color?->name ?? __('No color'),
                'available' => QuantityFormatter::format($current?->quantity_available ?? '0'),
                'after' => preg_match('/^\d{1,14}(?:\.0{1,4})?$/D', $quantity) ? QuantityFormatter::format((string) BigDecimal::of($current?->quantity_on_hand ?? '0')->plus($quantity)) : null];
        }

        return ['brand_selected' => filled($state['brand_id'] ?? null), 'product_selected' => $product !== null, 'source_selected' => $balance !== null, 'record_status' => $record?->status ?? 'new', 'product' => $product, 'source' => $source, 'location' => $location, 'color' => filled($colorId) ? ($this->colors($actor, $productId, $record)[$colorId] ?? __('Unavailable')) : __('All colors'),
            'image' => $image ? Storage::disk('public')->url($image->path) : asset('images/product-placeholder.svg'),
            'available' => QuantityFormatter::format((string) $available), 'total' => QuantityFormatter::format((string) $total),
            'remaining' => QuantityFormatter::format((string) $available->minus($total)), 'is_valid' => $valid, 'over_allocated' => $over,
            'valid_quantities' => $validQuantities, 'has_context' => $balance !== null, 'cost' => $cost, 'receipt' => $receipt, 'rows' => $rows];
    }
}
