<?php

namespace App\Modules\Sales\Services;

use App\Models\QuantityFormatter;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;

class SalesProductSelection
{
    public function variants(?User $actor, ?int $locationId = null, bool $availableOnly = true): Builder
    {
        $query = ProductVariant::where('is_active', true)->whereNull('unallocated_product_id')
            ->whereHas('product', fn (Builder $products) => $products->where('is_active', true)->where('status', 'active'));

        if ($locationId === null || ! app(SalesAccess::class)->allows($actor, SalesPermissions::VIEW, $locationId)) {
            return $query->whereRaw('1 = 0');
        }
        if ($availableOnly) {
            $query->whereHas('stockBalances', fn (Builder $balances) => $balances->where('location_id', $locationId)->where('quantity_available', '>', '0'));
        }

        return $query->addSelect(['sales_available_quantity' => StockBalance::select('quantity_available')
            ->whereColumn('variant_id', 'product_variants.id')->where('location_id', $locationId)]);
    }

    public function products(?User $actor, ?int $locationId = null, bool $availableOnly = true): Builder
    {
        return Product::where('is_active', true)->where('status', 'active')
            ->whereHas('variants', fn (Builder $variants) => $variants->whereIn('id', $this->variants($actor, $locationId, $availableOnly)->select('id')));
    }

    public function productOptions(?User $actor, ?int $locationId, string $search = '', mixed $brandId = null): array
    {
        return $this->products($actor, $locationId)->when(filled($brandId), fn (Builder $query) => $query->where('brand_id', $brandId))->when(trim($search) !== '', fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('name', 'like', '%'.trim($search).'%')
                ->orWhere('default_sku', 'like', '%'.trim($search).'%')))
            ->orderBy('name')->limit(50)->pluck('name', 'id')->all();
    }

    public function variantOptions(?User $actor, ?int $locationId, mixed $productId, string $search = ''): array
    {
        return $this->variants($actor, $locationId)->where('product_id', $productId)->with(['product', 'size', 'color'])
            ->when(trim($search) !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('sku', 'like', '%'.trim($search).'%')->orWhere('barcode', 'like', '%'.trim($search).'%')
                ->orWhereHas('size', fn (Builder $sizes) => $sizes->where('name', 'like', '%'.trim($search).'%'))
                ->orWhereHas('color', fn (Builder $colors) => $colors->where('name', 'like', '%'.trim($search).'%'))))
            ->orderBy('id')->limit(50)->get()->mapWithKeys(fn (ProductVariant $variant): array => [$variant->id => $this->label($variant)])->all();
    }

    public function label(ProductVariant $variant): string
    {
        return $variant->displayName().' · '.$variant->sku.' · '.__('Available quantity').': '.QuantityFormatter::format($variant->sales_available_quantity ?? '0.0000');
    }
}
