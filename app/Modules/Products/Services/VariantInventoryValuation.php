<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\InventoryCosting\Services\CategoryCostingManager;
use App\Modules\InventoryCosting\Services\EstimatedCostResolver;
use App\Modules\InventoryCosting\Services\ProfitabilityCalculator;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\PurchaseReceiptItem;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class VariantInventoryValuation
{
    public function canView(?User $actor): bool
    {
        $actor?->unsetRelation('roles')->unsetRelation('permissions');

        return app(ProductsAccess::class)->allows($actor, 'products.view')
            && app(ModuleAccess::class)->isActive('inventory_costing')
            && app(InventoryAccess::class)->allows($actor, InventoryPermissions::BALANCES)
            && app(InventoryAccess::class)->allows($actor, InventoryPermissions::COST);
    }

    public function locations(?User $actor): Builder
    {
        $query = Location::query()->where('is_active', true);
        if (! $this->canView($actor)) {
            return $query->whereRaw('1 = 0');
        }

        return $actor->isGlobalSuperAdmin() ? $query : $query->whereKey(getPermissionsTeamId());
    }

    public function authorize(?User $actor, ProductVariant $variant, ?int $locationId = null): void
    {
        abort_unless($this->canView($actor), 403);
        Gate::forUser($actor)->authorize('view', $variant);
        Gate::forUser($actor)->authorize('view', $variant->product);
        if ($locationId !== null) {
            abort_unless(Location::whereKey($locationId)->where('is_active', true)->exists(), 403);
            app(InventoryAccess::class)->authorize($actor, InventoryPermissions::BALANCES, $locationId);
            app(InventoryAccess::class)->authorize($actor, InventoryPermissions::COST, $locationId);
        }
    }

    public function read(User $actor, ProductVariant $variant, int $locationId, int $page = 1): array
    {
        $this->authorize($actor, $variant, $locationId);
        $variant->loadMissing(['product', 'color', 'size']);
        $balance = StockBalance::where('variant_id', $variant->id)->where('location_id', $locationId)->first();
        $state = InventoryCostState::where('variant_id', $variant->id)->where('location_id', $locationId)->first();
        $method = InventoryValuationSetting::where('category_id', $variant->product->category_id)->value('costing_method');
        $standardRevision = null;
        $standardProblem = false;
        if ($method === 'standard') {
            $standard = InventoryStandardCost::where('variant_id', $variant->id)->first();
            // Match receipt resolution: latest effective timestamp, then highest ID; conflicting ties are invalid.
            $standardRevision = $standard?->revisions()->where('effective_at', '<=', now())
                ->orderByDesc('effective_at')->orderByDesc('id')->first();
            if ($standardRevision !== null) {
                $standardProblem = $standard->revisions()->where('effective_at', $standardRevision->getRawOriginal('effective_at'))
                    ->where('new_cost', '!=', $standardRevision->new_cost)->exists();
            }
        }
        $layers = null;
        $fifoValue = null;
        $fifoQuantity = null;
        if ($method === 'fifo') {
            $query = $variant->fifoLayers()->where('remaining_qty', '>', '0')
                ->whereHas('stockMovement', fn (Builder $movement): Builder => $movement->where('location_id', $locationId));
            $aggregate = (clone $query)->selectRaw('COALESCE(SUM(remaining_qty), 0) AS remaining_quantity, COALESCE(SUM(ROUND(remaining_qty * unit_cost, 4)), 0) AS remaining_value')->first();
            $fifoValue = (string) $aggregate->remaining_value;
            $fifoQuantity = (string) $aggregate->remaining_quantity;
            $layers = $query->with('stockMovement')->orderBy('received_at')->orderBy('id')
                ->paginate(10, ['*'], 'valuationLayers', max(1, $page));
        }
        $latestPurchase = null;
        $canViewPurchase = app(PurchasingAccess::class)->allows($actor, PurchasingPermissions::RECEIPTS_VIEW, $locationId);
        $canViewReference = app(ProductsAccess::class)->referenceCost($actor);
        if ($canViewPurchase) {
            $latestPurchase = InventoryCostEntry::query()->join('stock_movements as movement', 'movement.id', '=', 'inventory_cost_entries.stock_movement_id')
                ->join('purchase_receipt_items as item', 'item.stock_movement_id', '=', 'movement.id')
                ->join('purchase_receipts as receipt', 'receipt.id', '=', 'item.purchase_receipt_id')
                ->where('movement.variant_id', $variant->id)->where('movement.location_id', $locationId)
                ->where('receipt.location_id', $locationId)->where('receipt.status', 'posted')->where('inventory_cost_entries.entry_type', 'receipt')
                ->whereNotNull('inventory_cost_entries.purchase_unit_cost')
                ->where('movement.reference_type', (new PurchaseReceiptItem)->getMorphClass())
                ->whereColumn('movement.reference_id', 'item.id')
                ->orderByDesc('receipt.posted_at')->orderByDesc('receipt.id')->orderByDesc('item.id')
                ->first(['inventory_cost_entries.purchase_unit_cost', 'receipt.receipt_number', 'receipt.posted_at']);
        }
        $physicalQuantity = $balance?->quantity_on_hand ?? '0.0000';
        $mismatch = $state !== null && ! BigDecimal::of($physicalQuantity)->isEqualTo($state->on_hand_qty);
        $fifoMismatch = $method === 'fifo' && $state !== null
            && (! BigDecimal::of($fifoQuantity)->isEqualTo($state->on_hand_qty) || ! BigDecimal::of($fifoValue)->isEqualTo($state->total_value));

        return [
            'profitability' => app(ProfitabilityCalculator::class)->calculate(app(SellingPriceResolver::class)->resolve($variant),
                app(EstimatedCostResolver::class)->lines([['variant_id' => $variant->id, 'quantity' => '1.0000']], $locationId)[0]['cost']),
            'variant' => $variant, 'location' => Location::findOrFail($locationId),
            'method' => $method, 'method_label' => CategoryCostingManager::METHODS[$method] ?? 'Not configured',
            'balance' => $balance, 'state' => $state, 'quantity' => $physicalQuantity,
            'mismatch' => $mismatch, 'fifo_mismatch' => $fifoMismatch,
            'layers' => $layers, 'fifo_value' => $fifoValue, 'fifo_quantity' => $fifoQuantity,
            'standard_revision' => $standardRevision, 'standard_problem' => $standardProblem,
            'latest_purchase' => $latestPurchase,
            'can_view_purchase' => $canViewPurchase,
            'reference_cost' => $canViewReference ? $variant->cost_price : null,
            'can_view_reference' => $canViewReference,
        ];
    }
}
