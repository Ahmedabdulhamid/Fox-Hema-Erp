<?php

namespace App\Modules\Inventory\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use Brick\Math\BigDecimal;

class ProductInventorySummary
{
    public function forProduct(User $actor, Product $product, ?int $locationId = null): array
    {
        app(InventoryAccess::class)->authorize($actor, InventoryPermissions::BALANCES, $locationId);
        $balances = app(InventoryAccess::class)->scope(StockBalance::query(), $actor, InventoryPermissions::BALANCES)
            ->whereHas('variant', fn ($query) => $query->where('product_id', $product->id))
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->with(['variant.size', 'variant.color', 'location'])->get();
        $locations = [];
        foreach ($balances->groupBy('location_id') as $id => $group) {
            $total = BigDecimal::zero();
            $unallocated = BigDecimal::zero();
            $rows = [];
            foreach ($group as $balance) {
                $total = $total->plus($balance->quantity_on_hand);
                if ($balance->variant->isUnallocated()) {
                    $unallocated = $unallocated->plus($balance->quantity_on_hand);
                }
                $rows[] = ['label' => $balance->variant->imageLabel(), 'sku' => $balance->variant->sku,
                    'is_unallocated' => $balance->variant->isUnallocated(), 'color' => $balance->variant->color?->name ?? __('No color'),
                    'on_hand' => $balance->quantity_on_hand, 'reserved' => $balance->quantity_reserved, 'available' => $balance->quantity_available];
            }
            $locations[] = ['id' => $id, 'name' => $group->first()->location->name,
                'total' => (string) $total, 'unallocated' => (string) $unallocated, 'rows' => $rows];
        }

        return ['product' => $product, 'locations' => $locations];
    }
}
