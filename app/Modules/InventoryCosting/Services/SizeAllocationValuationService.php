<?php

namespace App\Modules\InventoryCosting\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\Inventory\Data\StockPostingCommand;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\SizeAllocation;
use App\Modules\Inventory\Models\SizeAllocationItem;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\FifoLayerConsumption;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SizeAllocationValuationService
{
    public function post(SizeAllocation $allocation, User $actor): void
    {
        if (DB::transactionLevel() === 0 || $allocation->status !== 'draft') {
            throw new \LogicException('Allocation valuation requires the draft document transaction.');
        }
        abort_unless(app(ModuleAccess::class)->isActive('inventory_costing'), 403);
        $method = InventoryValuationSetting::where('category_id', $allocation->product->category_id)->lockForUpdate()->first()?->costing_method;
        if (! in_array($method, ['fifo', 'avco', 'standard'], true)) {
            $this->invalid('A supported category costing method is required.');
        }
        $items = $allocation->items()->with('variant')->orderBy('variant_id')->lockForUpdate()->get();
        $variants = $items->pluck('variant_id')->push($allocation->source_variant_id)->unique()->sort()->values();
        $states = [];
        $balances = [];
        $layers = [];
        $revisions = [];
        foreach ($variants as $variantId) {
            DB::table('stock_balances')->upsert([
                'uuid' => (string) Str::uuid7(), 'variant_id' => $variantId, 'location_id' => $allocation->location_id,
                'quantity_on_hand' => '0.0000', 'quantity_reserved' => '0.0000', 'quantity_available' => '0.0000',
                'created_at' => now(), 'updated_at' => now(),
            ], ['variant_id', 'location_id'], ['variant_id']);
            $balance = StockBalance::where('variant_id', $variantId)->where('location_id', $allocation->location_id)->lockForUpdate()->firstOrFail();
            $qty = BigDecimal::of($balance->quantity_on_hand);
            $reserved = BigDecimal::of($balance->quantity_reserved);
            if ($qty->isNegative() || $reserved->isNegative() || $reserved->isGreaterThan($qty)
                || ! BigDecimal::of($balance->quantity_available)->isEqualTo($qty->minus($reserved))) {
                $this->invalid('Stock balances require reconciliation before allocation.');
            }
            if (InventoryCostState::where('variant_id', $variantId)->whereNull('location_id')->lockForUpdate()->exists()) {
                $this->invalid('Legacy aggregate valuation requires reconciliation before allocation.');
            }
            $state = InventoryCostState::where('variant_id', $variantId)->where('location_id', $allocation->location_id)->lockForUpdate()->first();
            if ($state === null) {
                if (! $qty->isZero()) {
                    $this->invalid('Existing stock has no reconciled location valuation.');
                }
                $state = InventoryCostState::create(['variant_id' => $variantId, 'location_id' => $allocation->location_id,
                    'on_hand_qty' => '0.0000', 'total_value' => '0.0000', 'avg_cost' => '0.000000']);
            }
            $value = BigDecimal::of($state->total_value);
            if (! BigDecimal::of($state->on_hand_qty)->isEqualTo($qty) || $value->isNegative()
                || BigDecimal::of($state->avg_cost)->isNegative() || ($qty->isZero() && ! $value->isZero())) {
                $this->invalid('Physical stock and location valuation disagree.');
            }
            $lastEntry = InventoryCostEntry::whereHas('stockMovement', fn ($query) => $query
                ->where('variant_id', $variantId)->where('location_id', $allocation->location_id))->orderByDesc('id')->lockForUpdate()->first();
            if (! $qty->isZero() && $lastEntry !== null && $lastEntry->method !== $method) {
                $this->invalid('The category costing method changed. Reconcile existing valuation first.');
            }
            if ($method === 'fifo') {
                $variantLayers = FifoLayer::where('variant_id', $variantId)
                    ->whereHas('stockMovement', fn ($query) => $query->where('location_id', $allocation->location_id))
                    ->orderByRaw('COALESCE(available_at, received_at)')->orderBy('id')->lockForUpdate()->get();
                $layerQty = BigDecimal::zero();
                $layerValue = BigDecimal::zero();
                foreach ($variantLayers as $layer) {
                    $remaining = BigDecimal::of($layer->remaining_qty);
                    if ($remaining->isNegative() || $remaining->isGreaterThan($layer->quantity) || BigDecimal::of($layer->unit_cost)->isNegative()) {
                        $this->invalid('FIFO layer quantities or costs require reconciliation.');
                    }
                    $layerQty = $layerQty->plus($remaining);
                    $layerValue = $layerValue->plus(app(FifoLayerValue::class)->remaining($layer));
                }
                if (! $layerQty->isEqualTo($qty) || ! $layerValue->isEqualTo($value)) {
                    $this->invalid('FIFO layers and location valuation disagree.');
                }
                $layers[$variantId] = $variantLayers;
            }
            if ($method === 'standard') {
                $standard = InventoryStandardCost::where('variant_id', $variantId)->lockForUpdate()->first();
                $history = $standard?->revisions()->where('effective_at', '<=', now())
                    ->orderByDesc('effective_at')->orderByDesc('id')->lockForUpdate()->get();
                $revision = $history?->first();
                if ($revision === null || $history->filter(fn ($row): bool => $row->effective_at->equalTo($revision->effective_at))->pluck('new_cost')->unique()->count() !== 1) {
                    $this->invalid('An unambiguous effective standard-cost revision is required for every variant.');
                }
                if (! $this->value($qty, $revision->new_cost)->isEqualTo($value)) {
                    $this->invalid('Standard Cost carrying value differs from the effective revision. Reconcile before allocation.');
                }
                $revisions[$variantId] = $revision;
            }
            $states[$variantId] = $state;
            $balances[$variantId] = $balance;
        }
        $sourceId = $allocation->source_variant_id;
        $total = $items->reduce(fn (BigDecimal $sum, SizeAllocationItem $item): BigDecimal => $sum->plus($item->quantity), BigDecimal::zero());
        if ($total->isGreaterThan($balances[$sourceId]->quantity_available)) {
            $this->invalid('Allocation exceeds available unallocated stock at this location.');
        }
        $standardValues = [];
        if ($method === 'standard') {
            foreach ($revisions as $revision) {
                if (! BigDecimal::of($revision->new_cost)->isEqualTo($revisions[$sourceId]->new_cost)) {
                    $this->invalid('Standard Cost variance is not supported for size allocation. Source and target effective costs must match.');
                }
            }
            $source = $states[$sourceId];
            $sourceDelta = BigDecimal::of($source->total_value)->minus($this->value(
                BigDecimal::of($source->on_hand_qty)->minus($total), $revisions[$sourceId]->new_cost));
            $targetDelta = BigDecimal::zero();
            foreach ($items as $item) {
                $target = $states[$item->variant_id];
                $delta = $this->value(BigDecimal::of($target->on_hand_qty)->plus($item->quantity), $revisions[$item->variant_id]->new_cost)
                    ->minus($target->total_value);
                if ($delta->isNegative()) {
                    $this->invalid('Allocation would create an invalid carrying value.');
                }
                $standardValues[$item->id] = $delta;
                $targetDelta = $targetDelta->plus($delta);
            }
            // Assess the whole document: individual rounding differences may offset each other.
            if (! $sourceDelta->isEqualTo($targetDelta)) {
                $this->invalid('This Standard Cost split has a rounding variance. Allocation must preserve total inventory valuation.');
            }
        }
        $openingValue = array_reduce($states, fn (BigDecimal $sum, InventoryCostState $state): BigDecimal => $sum->plus($state->total_value), BigDecimal::zero());
        $openingQuantity = array_reduce($states, fn (BigDecimal $sum, InventoryCostState $state): BigDecimal => $sum->plus($state->on_hand_qty), BigDecimal::zero());
        foreach ($items as $item) {
            $remaining = BigDecimal::of($item->quantity);
            if ($method === 'fifo') {
                foreach ($layers[$sourceId] as $layer) {
                    if ($remaining->isZero()) {
                        break;
                    }
                    $available = BigDecimal::of($layer->remaining_qty);
                    if ($available->isZero()) {
                        continue;
                    }
                    $qty = $remaining->isLessThan($available) ? $remaining : $available;
                    $value = $layer->initial_value === null ? $this->value($qty, $layer->unit_cost)
                        : app(FifoLayerValue::class)->remaining($layer)->minus(app(FifoLayerValue::class)->at($layer, $available->minus($qty)));
                    $next = $available->minus($qty);
                    if (! app(FifoLayerValue::class)->remaining($layer)->minus(app(FifoLayerValue::class)->at($layer, $next))->isEqualTo($value)) {
                        $this->invalid('This FIFO split has a rounding variance. Reconcile the layer before allocation.');
                    }
                    $this->transfer($allocation, $item, $actor, $method, $qty, $value, $layer->unit_cost, $states, $layer);
                    $layer->remaining_qty = (string) $next->toScale(4);
                    if ($layer->initial_value !== null) {
                        $layer->remaining_value = (string) app(FifoLayerValue::class)->at($layer, $next)->toScale(4);
                    }
                    app(FifoLayerValue::class)->persist($layer);
                    $remaining = $remaining->minus($qty);
                }
                if (! $remaining->isZero()) {
                    $this->invalid('FIFO layers cannot cover this allocation.');
                }
            } else {
                $source = $states[$sourceId];
                $qty = $remaining;
                $value = $method === 'standard' ? $standardValues[$item->id]
                    : ($qty->isEqualTo($source->on_hand_qty) ? BigDecimal::of($source->total_value)
                        : BigDecimal::of($source->total_value)->multipliedBy($qty)->dividedBy($source->on_hand_qty, 4, RoundingMode::HalfUp));
                $unitCost = $method === 'standard' ? $revisions[$sourceId]->new_cost
                    : (string) BigDecimal::of($source->total_value)->dividedBy($source->on_hand_qty, 6, RoundingMode::HalfUp);
                $this->transfer($allocation, $item, $actor, $method, $qty, $value, $unitCost, $states, revisions: $revisions);
            }
        }
        $closingValue = BigDecimal::zero();
        $closingQuantity = BigDecimal::zero();
        foreach ($states as $variantId => $state) {
            $balance = $balances[$variantId]->refresh();
            if (! BigDecimal::of($state->on_hand_qty)->isEqualTo($balance->quantity_on_hand)) {
                $this->invalid('Allocation stock and valuation quantities disagree.');
            }
            if ($method === 'standard' && ! $this->value(BigDecimal::of($state->on_hand_qty), $revisions[$variantId]->new_cost)->isEqualTo($state->total_value)) {
                $this->invalid('Standard Cost carrying value differs from the effective revision. Reconcile before allocation.');
            }
            $closingValue = $closingValue->plus($state->total_value);
            $closingQuantity = $closingQuantity->plus($state->on_hand_qty);
        }
        if (! $openingValue->isEqualTo($closingValue) || ! $openingQuantity->isEqualTo($closingQuantity)) {
            $this->invalid('Allocation must preserve total inventory valuation.');
        }
    }

    /** @param array<int, InventoryCostState> $states */
    private function transfer(SizeAllocation $allocation, SizeAllocationItem $item, User $actor, string $method, BigDecimal $qty, BigDecimal $value, string $unitCost, array &$states, ?FifoLayer $layer = null, array $revisions = []): void
    {
        $quantity = (string) $qty->toScale(4);
        $identity = 'size-allocation:'.$item->uuid.':'.($layer?->id ?? 'carrying-value');
        $movements = app(InventoryPostingService::class)->postMany([
            new StockPostingCommand($allocation->sourceVariant, $allocation->location, StockMovementType::Adjustment,
                '-'.$quantity, $identity.':out', reference: $item, notes: $allocation->reference, valuationUnitCost: $unitCost),
            new StockPostingCommand($item->variant, $allocation->location, StockMovementType::Adjustment,
                $quantity, $identity.':in', reference: $item, notes: $allocation->reference, valuationUnitCost: $unitCost),
        ], $actor);
        $entries = [];
        foreach ($movements as $index => $movement) {
            $entries[$index] = InventoryCostEntry::create([
                'stock_movement_id' => $movement->id, 'method' => $method, 'entry_type' => 'adjustment',
                'quantity' => $index === 0 ? '-'.$quantity : $quantity, 'unit_cost' => $unitCost,
                'total_cost' => (string) ($index === 0 ? $value->negated() : $value)->toScale(4),
                'standard_cost_revision_id' => $revisions[$movement->variant_id]->id ?? null,
            ]);
            $state = $states[$movement->variant_id];
            $nextQty = BigDecimal::of($state->on_hand_qty)->plus($movement->quantity);
            $nextValue = BigDecimal::of($state->total_value)->plus($entries[$index]->total_cost);
            if ($nextQty->isNegative() || $nextValue->isNegative() || ($nextQty->isZero() && ! $nextValue->isZero())) {
                $this->invalid('Allocation would create an invalid carrying value.');
            }
            $state->fill(['on_hand_qty' => (string) $nextQty->toScale(4), 'total_value' => (string) $nextValue->toScale(4),
                'avg_cost' => $nextQty->isZero() ? '0.000000' : (string) $nextValue->dividedBy($nextQty, 6, RoundingMode::HalfUp)])->save();
        }
        if ($layer !== null) {
            FifoLayerConsumption::create(['cost_entry_id' => $entries[0]->id, 'fifo_layer_id' => $layer->id,
                'quantity' => $quantity, 'unit_cost' => $unitCost, 'total_cost' => (string) $value->toScale(4)]);
            $layerData = ['variant_id' => $item->variant_id, 'stock_movement_id' => $movements[1]->id,
                'quantity' => $quantity, 'remaining_qty' => $quantity, 'unit_cost' => $unitCost,
                'received_at' => $layer->received_at, 'source_layer_id' => $layer->id];
            if ($layer->initial_value !== null) {
                DB::table('fifo_layers')->insert($layerData + ['uuid' => (string) Str::uuid7(), 'initial_value' => (string) $value->toScale(4),
                    'remaining_value' => (string) $value->toScale(4), 'available_at' => $layer->available_at, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                FifoLayer::create($layerData);
            }
        }
    }

    private function value(BigDecimal $quantity, string $cost): BigDecimal
    {
        return $quantity->multipliedBy($cost)->toScale(4, RoundingMode::HalfUp);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['valuation' => __($message)]);
    }
}
