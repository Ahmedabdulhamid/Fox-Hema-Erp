<?php

namespace App\Modules\InventoryCosting\Services;

use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Purchasing\Models\PurchaseReceiptItem;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiptValuationService
{
    /**
     * Resolve receipt-side costing only. The caller owns document locks and the transaction.
     *
     * @param  list<PurchaseReceiptItem>  $items
     * @return array<int, array{method: string, unit_cost: string, revision_id: ?int}>
     */
    public function prepare(array $items, CarbonInterface $postedAt): array
    {
        $this->assertTransaction();
        abort_unless(app(ModuleAccess::class)->isActive('inventory_costing'), 403);
        $plans = [];
        foreach ($items as $item) {
            $variant = $item->purchaseOrderItem->variant;
            if (InventoryCostState::where('variant_id', $variant->id)->whereNull('location_id')->lockForUpdate()->exists()) {
                $this->invalid('Legacy aggregate cost state requires approved reconciliation before receiving this variant.');
            }
            $setting = InventoryValuationSetting::where('category_id', $variant->product->category_id)->lockForUpdate()->first();
            if ($setting === null || ! in_array($setting->costing_method, ['fifo', 'avco', 'standard'], true)) {
                $this->invalid('A supported category costing method is required.');
            }
            $cost = $item->unit_cost;
            $revisionId = null;
            if ($setting->costing_method === 'standard') {
                $standard = InventoryStandardCost::where('variant_id', $variant->id)->lockForUpdate()->first();
                if ($standard === null) {
                    $this->invalid('An effective standard-cost revision is required.');
                }
                $revisions = $standard->revisions()->where('effective_at', '<=', $postedAt)
                    ->orderByDesc('effective_at')->orderByDesc('id')->lockForUpdate()->get();
                $revision = $revisions->first();
                if ($revision === null) {
                    $this->invalid('An effective standard-cost revision is required.');
                }
                $ties = $revisions->filter(fn ($candidate): bool => $candidate->effective_at->equalTo($revision->effective_at));
                if ($ties->pluck('new_cost')->unique()->count() !== 1) {
                    $this->invalid('Conflicting standard-cost revisions share the same effective date. Reconcile the history.');
                }
                $cost = $revision->new_cost;
                $revisionId = (int) $revision->id;
            }
            $plans[$item->id] = ['method' => $setting->costing_method,
                'unit_cost' => app(PurchaseOrderCalculator::class)->decimal($cost, 'valuation', 18, 6),
                'revision_id' => $revisionId];
        }

        return $plans;
    }

    /**
     * InventoryPostingService has locked and posted the whole batch. Verify opening valued
     * quantity against opening physical quantity under those same locks before committing.
     *
     * @param  list<PurchaseReceiptItem>  $items  Sorted by variant then item ID.
     * @param  list<StockMovement>  $movements  Same order as items.
     * @param  array<int, array{method: string, unit_cost: string, revision_id: ?int}>  $plans
     */
    public function post(array $items, array $movements, array $plans, int $locationId, CarbonInterface $postedAt): void
    {
        $this->assertTransaction();
        $calculator = app(PurchaseOrderCalculator::class);
        $states = [];
        $incoming = [];
        foreach ($items as $item) {
            $variantId = $item->purchaseOrderItem->variant_id;
            $incoming[$variantId] = ($incoming[$variantId] ?? BigDecimal::zero())->plus($item->quantity);
        }
        foreach ($incoming as $variantId => $quantity) {
            $balance = StockBalance::where('variant_id', $variantId)->where('location_id', $locationId)->lockForUpdate()->firstOrFail();
            $opening = BigDecimal::of($balance->quantity_on_hand)->minus($quantity);
            $state = InventoryCostState::where('variant_id', $variantId)->where('location_id', $locationId)->lockForUpdate()->first();
            if ($opening->isNegative() || ($state === null && ! $opening->isZero())) {
                $this->invalid('Existing stock has no reconciled location valuation. Reconcile before receiving.');
            }
            if ($state === null) {
                $state = InventoryCostState::create(['variant_id' => $variantId, 'location_id' => $locationId,
                    'avg_cost' => '0.000000', 'on_hand_qty' => '0.0000', 'total_value' => '0.0000']);
            }
            $value = BigDecimal::of($state->total_value);
            if (! BigDecimal::of($state->on_hand_qty)->isEqualTo($opening) || $value->isNegative()
                || ($opening->isZero() && ! $value->isZero()) || BigDecimal::of($state->avg_cost)->isNegative()) {
                $this->invalid('Physical stock and location valuation disagree. Reconcile before receiving.');
            }
            $states[$variantId] = $state;
            $plan = $plans[collect($items)->first(fn (PurchaseReceiptItem $item): bool => (int) $item->purchaseOrderItem->variant_id === (int) $variantId)->id];
            $method = $plan['method'];
            if ($method === 'standard' && ! $opening->multipliedBy($plan['unit_cost'])->toScale(4, RoundingMode::HalfUp)->isEqualTo($value)) {
                $this->invalid('Standard Cost carrying value differs from the effective revision. Reconcile before receiving.');
            }
            if ($method === 'fifo') {
                $layers = FifoLayer::where('variant_id', $variantId)
                    ->whereHas('stockMovement', fn ($query) => $query->where('location_id', $locationId))
                    ->orderBy('id')->lockForUpdate()->get();
                $layerQty = BigDecimal::zero();
                $layerValue = BigDecimal::zero();
                foreach ($layers as $layer) {
                    $remaining = BigDecimal::of($layer->remaining_qty);
                    if ($remaining->isNegative() || $remaining->isGreaterThan($layer->quantity)) {
                        $this->invalid('FIFO layer balances require reconciliation before receiving.');
                    }
                    $layerQty = $layerQty->plus($remaining);
                    $layerValue = $layerValue->plus(app(FifoLayerValue::class)->remaining($layer));
                }
                if (! $layerQty->isEqualTo($opening) || ! $layerValue->isEqualTo($value)) {
                    $this->invalid('FIFO layers and location valuation disagree. Reconcile before receiving.');
                }
            }
        }
        foreach ($items as $index => $item) {
            $movement = $movements[$index];
            $plan = $plans[$item->id];
            $variantId = $item->purchaseOrderItem->variant_id;
            if ($movement->reference_type !== $item->getMorphClass() || (int) $movement->reference_id !== (int) $item->id
                || (int) $movement->variant_id !== (int) $variantId || (int) $movement->location_id !== $locationId
                || $movement->quantity !== $item->quantity || $movement->valuation_unit_cost !== $plan['unit_cost']
                || $movement->inventoryCostEntry()->exists() || $movement->fifoLayer()->exists()) {
                $this->invalid('Receipt movement identity is inconsistent or already valued.');
            }
            $purchase = $calculator->decimal((string) BigDecimal::of($item->quantity)->multipliedBy($item->unit_cost)->toScale(4, RoundingMode::HalfUp), 'purchase_value');
            $state = $states[$variantId];
            $nextQty = BigDecimal::of($state->on_hand_qty)->plus($item->quantity);
            // Standard receipts change the rounded closing target, never revalue opening stock.
            $valueDelta = $plan['method'] === 'standard'
                ? $nextQty->multipliedBy($plan['unit_cost'])->toScale(4, RoundingMode::HalfUp)->minus($state->total_value)
                : BigDecimal::of($item->quantity)->multipliedBy($plan['unit_cost'])->toScale(4, RoundingMode::HalfUp);
            if ($valueDelta->isNegative()) {
                $this->invalid('Receipt valuation cannot reduce inventory carrying value.');
            }
            $valuation = $calculator->decimal((string) $valueDelta, 'total_cost');
            $variance = BigDecimal::of($purchase)->minus($valuation)->toScale(4);
            InventoryCostEntry::create(['stock_movement_id' => $movement->id, 'method' => $plan['method'],
                'quantity' => $item->quantity, 'unit_cost' => $plan['unit_cost'], 'total_cost' => $valuation,
                'entry_type' => 'receipt', 'purchase_unit_cost' => $item->unit_cost, 'purchase_value' => $purchase,
                'purchase_price_variance' => (string) $variance, 'standard_cost_revision_id' => $plan['revision_id']]);
            if ($plan['method'] === 'fifo') {
                FifoLayer::create(['variant_id' => $variantId, 'stock_movement_id' => $movement->id,
                    'quantity' => $item->quantity, 'remaining_qty' => $item->quantity,
                    'unit_cost' => $plan['unit_cost'], 'received_at' => $postedAt]);
            }
            $nextValue = BigDecimal::of($state->total_value)->plus($valuation);
            $state->fill(['on_hand_qty' => $calculator->decimal((string) $nextQty, 'on_hand_qty', 18, 4),
                'total_value' => $calculator->decimal((string) $nextValue, 'total_value'),
                'avg_cost' => $calculator->decimal((string) $nextValue->dividedBy($nextQty, 6, RoundingMode::HalfUp), 'avg_cost', 18, 6)])->save();
        }
    }

    private function assertTransaction(): void
    {
        if (DB::connection()->transactionLevel() === 0) {
            throw new \LogicException('Receipt valuation requires the document transaction.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['valuation' => __($message)]);
    }
}
