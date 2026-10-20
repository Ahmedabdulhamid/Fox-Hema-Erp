<?php

namespace App\Modules\InventoryCosting\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\Inventory\Data\StockPostingCommand;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\SizeAllocationItem;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\FifoLayerConsumption;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesReturnCostAllocation;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesPermissions;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockIssueValuationService
{
    /** Exact readonly command instances computed here; never caller-supplied valuation plans. */
    private array $authorizedCommands = [];

    private ?int $actorId = null;

    public function authorizesCommand(StockPostingCommand $command, User $actor): bool
    {
        return DB::transactionLevel() > 0 && $this->actorId === (int) $actor->id
            && ($this->authorizedCommands[spl_object_id($command)] ?? null) === $command;
    }

    /**
     * Transaction primitive: the Sales posting service owns document locks and commit.
     * All valuation is prepared before the callback consumes the order's reservations.
     *
     * @param  list<SalesDeliveryItem>  $items  Sorted by variant then item ID.
     * @return list<StockMovement>
     */
    public function post(SalesDelivery $delivery, array $items, User $actor, CarbonInterface $postedAt, callable $consume): array
    {
        if (DB::transactionLevel() === 0 || $delivery->status !== 'draft' || $this->authorizedCommands !== []) {
            throw new \LogicException('Issue valuation requires the Sales delivery transaction.');
        }
        app(SalesAccess::class)->authorize($actor, SalesPermissions::DELIVERIES_VIEW, (int) $delivery->location_id);
        app(SalesAccess::class)->authorize($actor, SalesPermissions::DELIVERIES_POST, (int) $delivery->location_id);
        abort_unless(app(ModuleAccess::class)->isActive('inventory_costing'), 403);
        $calculator = app(PurchaseOrderCalculator::class);
        $states = [];
        $methods = [];
        $layers = [];
        $revisions = [];
        foreach ($items as $item) {
            $variant = $item->salesOrderItem->variant;
            if (isset($states[$variant->id])) {
                continue;
            }
            $setting = InventoryValuationSetting::where('category_id', $variant->product->category_id)->lockForUpdate()->first();
            $method = $setting?->costing_method;
            if (! in_array($method, ['fifo', 'avco', 'standard'], true)) {
                $this->invalid('A supported category costing method is required.');
            }
            if (InventoryCostState::where('variant_id', $variant->id)->whereNull('location_id')->lockForUpdate()->exists()) {
                $this->invalid('Legacy aggregate valuation requires reconciliation before delivery posting.');
            }
            if ($method === 'standard') {
                $standard = InventoryStandardCost::where('variant_id', $variant->id)->lockForUpdate()->first();
                $history = $standard?->revisions()->where('effective_at', '<=', $postedAt)->orderByDesc('effective_at')->orderByDesc('id')->lockForUpdate()->get();
                $revision = $history?->first();
                if ($revision === null || $history->filter(fn ($row): bool => $row->effective_at->equalTo($revision->effective_at))->pluck('new_cost')->unique()->count() !== 1) {
                    $this->invalid('An unambiguous effective standard-cost revision is required for every variant.');
                }
                $calculator->decimal($revision->new_cost, 'valuation', 18, 6);
                $revisions[$variant->id] = $revision;
            }
            $state = InventoryCostState::where('variant_id', $variant->id)->where('location_id', $delivery->location_id)->lockForUpdate()->first();
            $balance = StockBalance::where('variant_id', $variant->id)->where('location_id', $delivery->location_id)->lockForUpdate()->firstOrFail();
            if ($state === null || ! BigDecimal::of($state->on_hand_qty)->isEqualTo($balance->quantity_on_hand)
                || ! BigDecimal::of($state->on_hand_qty)->isPositive() || BigDecimal::of($state->total_value)->isNegative()
                || BigDecimal::of($state->avg_cost)->isNegative()) {
                $this->invalid('Physical stock and location valuation disagree. Reconcile before delivery posting.');
            }
            $last = InventoryCostEntry::whereHas('stockMovement', fn ($query) => $query->where('variant_id', $variant->id)
                ->where('location_id', $delivery->location_id))->orderByDesc('id')->lockForUpdate()->first();
            if ($last !== null && $last->method !== $method) {
                $this->invalid('The category costing method changed. Reconcile existing valuation first.');
            }
            if ($method === 'fifo') {
                $layers[$variant->id] = FifoLayer::where('variant_id', $variant->id)
                    ->whereHas('stockMovement', fn ($query) => $query->where('location_id', $delivery->location_id))
                    ->orderByRaw('COALESCE(available_at, received_at)')->orderBy('id')->lockForUpdate()->get();
                $this->assertLayers($layers[$variant->id], $state);
            }
            if ($method === 'standard' && ! $this->value(BigDecimal::of($state->on_hand_qty), $revisions[$variant->id]->new_cost)->isEqualTo($state->total_value)) {
                $this->invalid('Standard Cost carrying value differs from the effective revision. Revaluation is required before delivery posting.');
            }
            $states[$variant->id] = $state;
            $methods[$variant->id] = $method;
        }
        $plans = [];
        $commands = [];
        foreach ($items as $item) {
            $variant = $item->salesOrderItem->variant;
            $state = $states[$variant->id];
            $method = $methods[$variant->id];
            $quantity = BigDecimal::of($item->quantity);
            $openingQty = BigDecimal::of($state->on_hand_qty);
            $openingValue = BigDecimal::of($state->total_value);
            if (! $quantity->isPositive() || $quantity->isGreaterThan($openingQty)) {
                $this->invalid('Issue quantity exceeds reconciled location valuation.');
            }
            $closingQty = $openingQty->minus($quantity);
            $parts = [];
            if ($method === 'fifo') {
                $remaining = $quantity;
                $value = BigDecimal::zero();
                foreach ($layers[$variant->id] as $layer) {
                    if ($remaining->isZero()) {
                        break;
                    }
                    $available = BigDecimal::of($layer->remaining_qty);
                    if ($available->isZero()) {
                        continue;
                    }
                    $used = $remaining->isLessThan($available) ? $remaining : $available;
                    $next = $available->minus($used);
                    // Residual policy: cost is the reduction in rounded layer carrying value.
                    // This telescopes across partial issues and preserves exact closing layer value.
                    $partValue = app(FifoLayerValue::class)->consume($layer, $used);
                    $parts[] = ['fifo_layer_id' => $layer->id, 'quantity' => (string) $used->toScale(4),
                        'unit_cost' => $layer->unit_cost, 'total_cost' => (string) $partValue->toScale(4)];
                    $value = $value->plus($partValue);
                    $remaining = $remaining->minus($used);
                    $layer->remaining_qty = (string) $next->toScale(4);
                }
                if (! $remaining->isZero()) {
                    $this->invalid('FIFO layers cannot cover this delivery.');
                }
                $unitCost = (string) $value->dividedBy($quantity, 6, RoundingMode::HalfUp);
            } elseif ($method === 'standard') {
                $unitCost = $revisions[$variant->id]->new_cost;
                $value = $openingValue->minus($this->value($closingQty, $unitCost));
            } else {
                $unitCost = (string) $openingValue->dividedBy($openingQty, 6, RoundingMode::HalfUp);
                $value = $closingQty->isZero() ? $openingValue : $openingValue->multipliedBy($quantity)->dividedBy($openingQty, 4, RoundingMode::HalfUp);
            }
            $closingValue = $openingValue->minus($value);
            if ($value->isNegative() || $closingValue->isNegative() || ($closingQty->isZero() && ! $closingValue->isZero())) {
                $this->invalid('Issue would create an invalid inventory carrying value.');
            }
            $unitCost = $calculator->decimal($unitCost, 'valuation', 18, 6);
            $plans[$item->id] = ['method' => $method, 'unit_cost' => $unitCost,
                'value' => $calculator->decimal((string) $value, 'total_cost'), 'parts' => $parts,
                'revision_id' => $revisions[$variant->id]->id ?? null];
            $state->fill(['on_hand_qty' => $calculator->decimal((string) $closingQty, 'on_hand_qty', 18, 4),
                'total_value' => $calculator->decimal((string) $closingValue, 'total_value'),
                'avg_cost' => $closingQty->isZero() ? '0.000000' : $calculator->decimal((string) $closingValue->dividedBy($closingQty, 6, RoundingMode::HalfUp), 'avg_cost', 18, 6)]);
            $commands[] = new StockPostingCommand($variant, $delivery->location, StockMovementType::Sale,
                (string) $quantity->negated()->toScale(4), 'sales-delivery-item:'.$item->uuid.':post',
                reference: $item, valuationUnitCost: $unitCost);
        }
        $consume();
        $this->actorId = (int) $actor->id;
        foreach ($commands as $command) {
            $this->authorizedCommands[spl_object_id($command)] = $command;
        }
        try {
            $movements = app(InventoryPostingService::class)->postMany($commands, $actor);
        } finally {
            $this->authorizedCommands = [];
            $this->actorId = null;
        }
        foreach ($items as $index => $item) {
            $movement = $movements[$index];
            $plan = $plans[$item->id];
            if ($movement->inventoryCostEntry()->exists()) {
                $this->invalid('Issue movement already has valuation evidence.');
            }
            $entry = InventoryCostEntry::create(['stock_movement_id' => $movement->id, 'method' => $plan['method'], 'entry_type' => 'issue',
                'quantity' => $movement->quantity, 'unit_cost' => $plan['unit_cost'],
                'total_cost' => (string) BigDecimal::of($plan['value'])->negated()->toScale(4), 'standard_cost_revision_id' => $plan['revision_id']]);
            foreach ($plan['parts'] as $part) {
                FifoLayerConsumption::create($part + ['cost_entry_id' => $entry->id]);
            }
        }
        foreach ($layers as $variantLayers) {
            foreach ($variantLayers as $layer) {
                if ($layer->isDirty('remaining_qty')) {
                    app(FifoLayerValue::class)->persist($layer);
                }
            }
        }
        foreach ($states as $state) {
            $state->save();
        }
        $this->verifyCurrent(array_keys($states), (int) $delivery->location_id);

        return $movements;
    }

    /** @param list<int> $variantIds */
    public function verifyCurrent(array $variantIds, int $locationId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Issue verification requires the document transaction.');
        }
        sort($variantIds, SORT_NUMERIC);
        foreach (array_unique($variantIds) as $variantId) {
            $balance = StockBalance::where('variant_id', $variantId)->where('location_id', $locationId)->lockForUpdate()->first();
            $state = InventoryCostState::where('variant_id', $variantId)->where('location_id', $locationId)->lockForUpdate()->first();
            if ($balance === null || $state === null || ! BigDecimal::of($state->on_hand_qty)->isEqualTo($balance->quantity_on_hand)
                || BigDecimal::of($state->on_hand_qty)->isNegative() || BigDecimal::of($state->total_value)->isNegative()
                || BigDecimal::of($state->avg_cost)->isNegative()
                || (BigDecimal::of($state->on_hand_qty)->isZero() && (! BigDecimal::of($state->total_value)->isZero() || ! BigDecimal::of($state->avg_cost)->isZero()))) {
                $this->invalid('Physical stock and location valuation disagree. Reconcile before delivery posting.');
            }
            $last = InventoryCostEntry::whereHas('stockMovement', fn ($query) => $query->where('variant_id', $variantId)->where('location_id', $locationId))
                ->orderByDesc('id')->lockForUpdate()->first();
            if ($last?->method === 'fifo') {
                $layers = FifoLayer::where('variant_id', $variantId)->whereHas('stockMovement', fn ($query) => $query->where('location_id', $locationId))
                    ->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();
                $this->assertLayers($layers, $state);
            }
        }
    }

    private function assertLayers(Collection $layers, InventoryCostState $state): void
    {
        $quantity = BigDecimal::zero();
        $value = BigDecimal::zero();
        foreach ($layers as $layer) {
            $remaining = BigDecimal::of($layer->remaining_qty);
            if ($remaining->isNegative() || $remaining->isGreaterThan($layer->quantity) || BigDecimal::of($layer->unit_cost)->isNegative()
                || (int) $layer->stockMovement->variant_id !== (int) $layer->variant_id
                || ! BigDecimal::of($layer->quantity)->isPositive()) {
                $this->invalid('FIFO layers require reconciliation before delivery posting.');
            }
            if ($remaining->isPositive()) {
                $this->receiptProvenance($layer);
            }
            $quantity = $quantity->plus($remaining);
            $value = $value->plus(app(FifoLayerValue::class)->remaining($layer));
        }
        if (! $quantity->isEqualTo($state->on_hand_qty) || ! $value->isEqualTo($state->total_value)) {
            $this->invalid('FIFO layers and location valuation disagree. Reconcile before delivery posting.');
        }
    }

    private function value(BigDecimal $quantity, string $unitCost): BigDecimal
    {
        return $quantity->multipliedBy($unitCost)->toScale(4, RoundingMode::HalfUp);
    }

    /** Immutable receipt lineage; remaining quantities are deliberately excluded from replay evidence. */
    public function receiptProvenance(FifoLayer $layer): array
    {
        $root = $layer;
        $visited = [];
        $lineage = [];
        while ($root->source_layer_id !== null) {
            if (isset($visited[$root->id])) {
                $this->invalid('FIFO receipt provenance requires reconciliation before delivery posting.');
            }
            $visited[$root->id] = true;
            $lineage[] = $root->only(['id', 'uuid', 'variant_id', 'stock_movement_id', 'quantity', 'unit_cost', 'received_at', 'source_layer_id']);
            $parent = $root->sourceLayer;
            if ($parent === null || $parent->unit_cost !== $root->unit_cost) {
                $this->invalid('FIFO receipt provenance requires reconciliation before delivery posting.');
            }
            if ($root->initial_value !== null) {
                $lineage[array_key_last($lineage)]['exact_value'] = $this->returnLayerProvenance($root, $parent);
            }
            $root = $parent;
        }
        $receipt = $root->stockMovement->purchaseReceiptItem?->purchaseReceipt;
        if ($root->initial_value !== null || $receipt === null || $receipt->status !== 'posted' || $receipt->posted_at === null
            || ! $layer->received_at->equalTo($receipt->posted_at)
            || $root->stockMovement->inventoryCostEntry?->method !== 'fifo') {
            $this->invalid('FIFO receipt provenance requires reconciliation before delivery posting.');
        }
        $lineage[] = $root->only(['id', 'uuid', 'variant_id', 'stock_movement_id', 'quantity', 'unit_cost', 'received_at', 'source_layer_id']);

        return [$lineage, $receipt->only(['id', 'receipt_number', 'posted_at'])];
    }

    private function returnLayerProvenance(FifoLayer $layer, FifoLayer $parent): array
    {
        $metadata = $layer->only(['initial_value', 'available_at', 'return_cost_allocation_id']);
        if ($layer->return_cost_allocation_id === null) {
            // A size-allocation child must retain exact-value ancestry and stock receipt age.
            if ($parent->initial_value === null || ! $layer->available_at?->equalTo($parent->available_at)
                || $layer->stockMovement->reference_type !== SizeAllocationItem::class) {
                $this->invalid('FIFO exact-value ancestry is inconsistent.');
            }

            return $metadata;
        }
        $allocation = SalesReturnCostAllocation::find($layer->return_cost_allocation_id);
        $part = $allocation === null ? null : FifoLayerConsumption::find($allocation->fifo_consumption_id);
        $return = $allocation?->salesReturn;
        $movement = $layer->stockMovement;
        $entry = $movement->inventoryCostEntry;
        if ($allocation === null || $part === null || $return?->status !== 'posted' || $return->posting_payload_hash === null
            || $return->posted_at === null || $return->posted_by === null
            || $allocation->method !== 'fifo' || $allocation->disposition !== 'restock'
            || (int) $part->fifo_layer_id !== (int) $parent->id || (int) $part->cost_entry_id !== (int) $allocation->original_cost_entry_id
            || (int) $allocation->stock_movement_id !== (int) $movement->id || (int) $allocation->variant_id !== (int) $layer->variant_id
            || (int) $allocation->location_id !== (int) $movement->location_id
            || $allocation->quantity !== $layer->quantity || $allocation->original_unit_cost !== $layer->unit_cost
            || $allocation->carrying_value !== $layer->initial_value || $allocation->original_cost !== $allocation->carrying_value
            || BigDecimal::of($allocation->quantity)->isGreaterThan($part->quantity)
            || BigDecimal::of($allocation->original_cost)->isGreaterThan($part->total_cost)
            || ! $layer->available_at?->equalTo($return->posted_at)
            || $movement->type !== StockMovementType::Return || $movement->reference_type !== $allocation->getMorphClass()
            || (int) $movement->reference_id !== (int) $allocation->id || $movement->quantity !== $layer->quantity
            || $entry?->method !== 'fifo' || $entry->entry_type !== 'reversal' || $entry->total_cost !== $allocation->carrying_value
            || $entry->quantity !== $allocation->quantity || $entry->unit_cost !== $allocation->original_unit_cost) {
            $this->invalid('FIFO returned-layer provenance is inconsistent.');
        }
        app(InventoryPostingService::class)->verifyRecordedPayload($movement);

        return $metadata + ['allocation' => $allocation->getAttributes()];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['record' => __($message)]);
    }
}
