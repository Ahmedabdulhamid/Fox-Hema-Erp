<?php

namespace App\Modules\InventoryCosting\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\Inventory\Data\StockPostingCommand;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\FifoLayerConsumption;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\StockTransfer\Models\StockTransfer;
use App\Modules\StockTransfer\Models\StockTransferReceiptItem;
use App\Modules\StockTransfer\Models\StockTransferShipmentItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockTransferValuationService
{
    /** @var array<int, StockPostingCommand> */
    private array $authorizedCommands = [];

    private ?int $actorId = null;

    public function authorizesCommand(StockPostingCommand $command, User $actor): bool
    {
        return DB::transactionLevel() > 0 && $this->actorId === (int) $actor->id
            && ($this->authorizedCommands[spl_object_id($command)] ?? null) === $command;
    }

    /**
     * @param  list<StockTransferShipmentItem>  $items
     */
    public function dispatch(StockTransfer $transfer, array $items, User $actor): void
    {
        $this->assertTransaction();
        $locationId = (int) $transfer->from_location_id;
        $states = [];
        $fifoLayers = [];
        $plans = [];
        $commands = [];

        foreach ($items as $item) {
            $transferItem = $item->stockTransferItem()->with('variant.product')->firstOrFail();
            $variant = $transferItem->variant;
            $setting = InventoryValuationSetting::query()->where('category_id', $variant->product->category_id)->lockForUpdate()->first();
            $locationIds = [(int) $transfer->from_location_id, (int) $transfer->to_location_id];
            sort($locationIds, SORT_NUMERIC);
            $lockedStates = InventoryCostState::query()->where('variant_id', $variant->id)->whereIn('location_id', $locationIds)
                ->orderBy('location_id')->lockForUpdate()->get()->keyBy('location_id');
            $balances = StockBalance::query()->where('variant_id', $variant->id)->whereIn('location_id', $locationIds)
                ->orderBy('location_id')->lockForUpdate()->get()->keyBy('location_id');
            $state = $lockedStates->get($locationId);
            $destinationState = $lockedStates->get((int) $transfer->to_location_id);
            $balance = $balances->get($locationId);
            $destinationBalance = $balances->get((int) $transfer->to_location_id);
            $latestEntry = $this->latestEntry((int) $variant->id, $locationId);
            $destinationEntry = $this->latestEntry((int) $variant->id, (int) $transfer->to_location_id);
            if ($setting === null && $state === null && $latestEntry === null
                && $destinationState === null && $destinationEntry === null) {
                $plans[$item->id] = null;
                $commands[] = $this->command($variant, $transfer, $item, StockMovementType::TransferOut,
                    (string) BigDecimal::of($item->shipped_quantity)->negated()->toScale(4), $actor, null);

                continue;
            }
            if ($setting === null || $state === null || $balance === null || ! app(ModuleAccess::class)->isActive('inventory_costing')) {
                $this->invalid('Costed stock requires an active costing method and reconciled location valuation.');
            }
            $method = (string) $setting->costing_method;
            $this->assertState($state, $balance, $latestEntry, $method);
            $revision = $method === 'standard' ? $this->standardRevision($variant) : null;
            if ($method === 'standard'
                && ! $this->value(BigDecimal::of($state->on_hand_qty), $revision['unit_cost'])->isEqualTo($state->total_value)) {
                $this->invalid('Standard Cost carrying value differs from the effective revision.');
            }
            if ($destinationState !== null) {
                $this->assertState($destinationState, $destinationBalance, $destinationEntry, $method);
                if ($method === 'standard'
                    && ! $this->value(BigDecimal::of($destinationState->on_hand_qty), $revision['unit_cost'])->isEqualTo($destinationState->total_value)) {
                    $this->invalid('Standard Cost destination carrying value differs from the effective revision.');
                }
            } elseif ($destinationBalance !== null && BigDecimal::of($destinationBalance->quantity_on_hand)->isPositive()) {
                $this->invalid('Destination stock requires reconciliation before receiving valued transfers.');
            } elseif ($destinationEntry !== null) {
                $this->invalid('Destination valuation history has no matching location cost state.');
            }
            if ($method === 'fifo') {
                $fifoLayers[$variant->id] = $this->layers($variant, $locationId);
                $this->assertFifoLayers($fifoLayers[$variant->id], $state);
            }

            $quantity = BigDecimal::of($item->shipped_quantity);
            $openingQuantity = BigDecimal::of($state->on_hand_qty);
            $openingValue = BigDecimal::of($state->total_value);
            if (! $quantity->isPositive() || $quantity->isGreaterThan($openingQuantity)) {
                $this->invalid('Transfer quantity exceeds valued source stock.');
            }
            $closingQuantity = $openingQuantity->minus($quantity);
            $parts = [];
            if ($method === 'fifo') {
                [$value, $parts] = $this->consumeLayers($fifoLayers[$variant->id], $quantity);
                $unitCost = $value->dividedBy($quantity, 6, RoundingMode::HalfUp);
            } elseif ($method === 'standard') {
                $unitCost = BigDecimal::of($revision['unit_cost']);
                $value = $openingValue->minus($this->value($closingQuantity, $revision['unit_cost']));
            } else {
                $unitCost = $openingValue->dividedBy($openingQuantity, 6, RoundingMode::HalfUp);
                $value = $closingQuantity->isZero()
                    ? $openingValue
                    : $openingValue->multipliedBy($quantity)->dividedBy($openingQuantity, 4, RoundingMode::HalfUp);
            }
            $closingValue = $openingValue->minus($value);
            if ($value->isNegative() || $closingValue->isNegative()
                || ($closingQuantity->isZero() && ! $closingValue->isZero())) {
                $this->invalid('Transfer would create an invalid source carrying value.');
            }
            $plans[$item->id] = [
                'method' => $method,
                'unit_cost' => (string) $unitCost->toScale(6, RoundingMode::HalfUp),
                'value' => (string) $value->toScale(4),
                'parts' => $parts,
                'revision_id' => $revision['revision_id'] ?? null,
            ];
            $state->fill([
                'on_hand_qty' => (string) $closingQuantity->toScale(4),
                'total_value' => (string) $closingValue->toScale(4),
                'avg_cost' => $closingQuantity->isZero() ? '0.000000' : (string) $closingValue->dividedBy($closingQuantity, 6, RoundingMode::HalfUp),
            ]);
            $states[$variant->id] = $state;
            $commands[] = $this->command($variant, $transfer, $item, StockMovementType::TransferOut,
                (string) $quantity->negated()->toScale(4), $actor, $plans[$item->id]);
        }

        $movements = $this->post($commands, $actor);
        foreach ($items as $index => $item) {
            $plan = $plans[$item->id];
            if ($plan === null) {
                continue;
            }
            $entry = InventoryCostEntry::query()->create([
                'stock_movement_id' => $movements[$index]->id,
                'method' => $plan['method'],
                'quantity' => $movements[$index]->quantity,
                'unit_cost' => $plan['unit_cost'],
                'total_cost' => (string) BigDecimal::of($plan['value'])->negated()->toScale(4),
                'entry_type' => 'issue',
                'standard_cost_revision_id' => $plan['revision_id'],
            ]);
            foreach ($plan['parts'] as $part) {
                FifoLayerConsumption::query()->create($part + ['cost_entry_id' => $entry->id]);
            }
        }
        foreach ($fifoLayers as $layers) {
            foreach ($layers as $layer) {
                if ($layer->isDirty('remaining_qty')) {
                    app(FifoLayerValue::class)->persist($layer);
                }
            }
        }
        foreach ($states as $state) {
            $state->save();
        }
        foreach (array_keys($states) as $variantId) {
            app(StockIssueValuationService::class)->verifyCurrent([(int) $variantId], $locationId);
        }
    }

    /**
     * @param  list<StockTransferReceiptItem>  $items
     */
    public function receive(StockTransfer $transfer, array $items, User $actor): void
    {
        $this->assertTransaction();
        $locationId = (int) $transfer->to_location_id;
        $states = [];
        $plans = [];
        $commands = [];

        foreach ($items as $item) {
            $shipmentItem = $item->stockTransferShipmentItem()->with('stockTransferItem.variant')->firstOrFail();
            $variant = $shipmentItem->stockTransferItem->variant;
            $outMovement = $this->transferMovement($shipmentItem, StockMovementType::TransferOut);
            $outEntry = $outMovement->inventoryCostEntry()->lockForUpdate()->first();
            if ($outEntry === null) {
                $plans[$item->id] = null;
                $commands[] = $this->command($variant, $transfer, $item, StockMovementType::TransferIn,
                    (string) BigDecimal::of($item->received_quantity)->toScale(4), $actor, null);

                continue;
            }
            $method = (string) $outEntry->method;
            $setting = InventoryValuationSetting::query()->where('category_id', $variant->product->category_id)->lockForUpdate()->first();
            if ($setting === null || $setting->costing_method !== $method
                || ! app(ModuleAccess::class)->isActive('inventory_costing')) {
                $this->invalid('The costing method changed while this transfer was in transit.');
            }
            $state = $this->destinationState($variant, $locationId);
            $balance = StockBalance::query()->where('variant_id', $variant->id)->where('location_id', $locationId)->lockForUpdate()->first();
            $latestEntry = $this->latestEntry((int) $variant->id, $locationId);
            $this->assertState($state, $balance, $latestEntry, $method);
            $revision = $method === 'standard' ? $this->standardRevision($variant) : null;
            if ($method === 'standard'
                && ($revision['revision_id'] !== (int) $outEntry->standard_cost_revision_id
                    || ! $this->value(BigDecimal::of($state->on_hand_qty), $revision['unit_cost'])->isEqualTo($state->total_value))) {
                $this->invalid('Standard Cost destination or in-transit revision requires reconciliation before receiving.');
            }

            $quantity = BigDecimal::of($item->received_quantity);
            $shipped = BigDecimal::of($shipmentItem->shipped_quantity);
            $previousQuantity = BigDecimal::of((string) $shipmentItem->receiptItems()
                ->where('id', '!=', $item->id)
                ->whereHas('stockTransferReceipt', fn ($query) => $query->where('status', 'posted'))
                ->sum('received_quantity'));
            if (! $quantity->isPositive() || $previousQuantity->plus($quantity)->isGreaterThan($shipped)) {
                $this->invalid('Received quantity exceeds the dispatched quantity.');
            }
            $sourceValue = BigDecimal::of($outEntry->total_cost)->abs();
            $priorValue = $this->receivedValue($shipmentItem, (int) $item->id);
            $cumulativeQuantity = $previousQuantity->plus($quantity);
            $cumulativeValue = $cumulativeQuantity->isEqualTo($shipped)
                ? $sourceValue
                : $sourceValue->multipliedBy($cumulativeQuantity)->dividedBy($shipped, 4, RoundingMode::HalfUp);
            $value = $cumulativeValue->minus($priorValue);
            if ($value->isNegative()) {
                $this->invalid('Receipt valuation exceeds the dispatched carrying value.');
            }
            $unitCost = $value->isZero() ? BigDecimal::zero() : $value->dividedBy($quantity, 6, RoundingMode::HalfUp);
            $closingQuantity = BigDecimal::of($state->on_hand_qty)->plus($quantity);
            $transferredValue = $value;
            if ($method === 'standard') {
                $unitCost = BigDecimal::of($revision['unit_cost']);
                $value = $this->value($closingQuantity, $revision['unit_cost'])->minus($state->total_value);
            }
            $closingValue = BigDecimal::of($state->total_value)->plus($value);
            $state->fill([
                'on_hand_qty' => (string) $closingQuantity->toScale(4),
                'total_value' => (string) $closingValue->toScale(4),
                'avg_cost' => $closingQuantity->isZero() ? '0.000000' : (string) $closingValue->dividedBy($closingQuantity, 6, RoundingMode::HalfUp),
            ]);
            $plans[$item->id] = [
                'method' => $method,
                'unit_cost' => (string) $unitCost->toScale(6, RoundingMode::HalfUp),
                'value' => (string) $value->toScale(4),
                'revision_id' => $outEntry->standard_cost_revision_id,
                'transferred_value' => $method === 'standard' ? (string) $transferredValue->toScale(4) : null,
                'variance' => $method === 'standard' ? (string) $transferredValue->minus($value)->toScale(4) : null,
                'received_at' => $item->stockTransferReceipt->posted_at ?? now(),
            ];
            $states[$variant->id] = $state;
            $commands[] = $this->command($variant, $transfer, $item, StockMovementType::TransferIn,
                (string) $quantity->toScale(4), $actor, $plans[$item->id]);
        }

        $movements = $this->post($commands, $actor);
        foreach ($items as $index => $item) {
            $plan = $plans[$item->id];
            if ($plan === null) {
                continue;
            }
            $entry = InventoryCostEntry::query()->create([
                'stock_movement_id' => $movements[$index]->id,
                'method' => $plan['method'],
                'quantity' => $movements[$index]->quantity,
                'unit_cost' => $plan['unit_cost'],
                'total_cost' => $plan['value'],
                'entry_type' => 'receipt',
                'standard_cost_revision_id' => $plan['revision_id'],
                'purchase_unit_cost' => $plan['method'] === 'standard' ? $plan['unit_cost'] : null,
                'purchase_value' => $plan['transferred_value'],
                'purchase_price_variance' => $plan['variance'],
            ]);
            if ($plan['method'] === 'fifo') {
                DB::table('fifo_layers')->insert([
                    'uuid' => (string) Str::uuid7(),
                    'variant_id' => $movements[$index]->variant_id,
                    'stock_movement_id' => $movements[$index]->id,
                    'quantity' => $movements[$index]->quantity,
                    'unit_cost' => $plan['unit_cost'],
                    'remaining_qty' => $movements[$index]->quantity,
                    'received_at' => $plan['received_at'],
                    'initial_value' => $plan['value'],
                    'remaining_value' => $plan['value'],
                    'available_at' => $plan['received_at'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
        foreach ($states as $state) {
            $state->save();
        }
        foreach (array_keys($states) as $variantId) {
            app(StockIssueValuationService::class)->verifyCurrent([(int) $variantId], $locationId);
        }
    }

    /**
     * @param  array{method: string, unit_cost: string, value: string, parts?: list<array<string, int|string>>, revision_id: ?int}|null  $plan
     */
    private function command(ProductVariant $variant, StockTransfer $transfer, StockTransferShipmentItem|StockTransferReceiptItem $item,
        StockMovementType $type, string $quantity, User $actor, ?array $plan): StockPostingCommand
    {
        $reference = $item;

        return new StockPostingCommand(
            $variant,
            $type === StockMovementType::TransferOut ? $transfer->fromLocation : $transfer->toLocation,
            $type,
            $quantity,
            'stock-transfer-'.$type->value.':'.$item->uuid,
            reference: $reference,
            unitCost: $plan === null ? null : (string) BigDecimal::of($plan['unit_cost'])->toScale(4, RoundingMode::HalfUp),
            valuationUnitCost: $plan['unit_cost'] ?? null,
        );
    }

    /** @param list<StockPostingCommand> $commands
     * @return list<StockMovement>
     */
    private function post(array $commands, User $actor): array
    {
        $this->actorId = (int) $actor->id;
        foreach ($commands as $command) {
            $this->authorizedCommands[spl_object_id($command)] = $command;
        }
        try {
            return app(InventoryPostingService::class)->postMany($commands, $actor);
        } finally {
            $this->authorizedCommands = [];
            $this->actorId = null;
        }
    }

    private function destinationState(ProductVariant $variant, int $locationId): InventoryCostState
    {
        DB::table('inventory_cost_states')->insertOrIgnore([
            'uuid' => (string) Str::uuid7(),
            'variant_id' => $variant->id,
            'location_id' => $locationId,
            'avg_cost' => '0.000000',
            'on_hand_qty' => '0.0000',
            'total_value' => '0.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return InventoryCostState::query()->where('variant_id', $variant->id)->where('location_id', $locationId)
            ->lockForUpdate()->firstOrFail();
    }

    private function latestEntry(int $variantId, int $locationId): ?InventoryCostEntry
    {
        return InventoryCostEntry::query()->whereHas('stockMovement', fn ($query) => $query->where('variant_id', $variantId)
            ->where('location_id', $locationId))->orderByDesc('id')->lockForUpdate()->first();
    }

    private function transferMovement(StockTransferShipmentItem $item, StockMovementType $type): StockMovement
    {
        return StockMovement::query()->where('reference_type', $item->getMorphClass())
            ->where('reference_id', $item->id)->where('type', $type->value)->lockForUpdate()->firstOrFail();
    }

    private function receivedValue(StockTransferShipmentItem $shipmentItem, int $excludingReceiptItemId): BigDecimal
    {
        $movements = StockMovement::query()->where('reference_type', (new StockTransferReceiptItem)->getMorphClass())
            ->whereIn('reference_id', $shipmentItem->receiptItems()->whereKeyNot($excludingReceiptItemId)->whereHas(
                'stockTransferReceipt', fn ($query) => $query->where('status', 'posted'))->select('id'))
            ->with('inventoryCostEntry')->get();

        return $movements->reduce(fn (BigDecimal $total, StockMovement $movement): BigDecimal => $total->plus(
            $movement->inventoryCostEntry?->purchase_value ?? $movement->inventoryCostEntry?->total_cost ?? '0'), BigDecimal::zero());
    }

    private function standardRevision(ProductVariant $variant): array
    {
        $standard = InventoryStandardCost::query()->where('variant_id', $variant->id)->lockForUpdate()->first();
        $history = $standard?->revisions()->where('effective_at', '<=', now())->orderByDesc('effective_at')
            ->orderByDesc('id')->lockForUpdate()->get();
        $revision = $history?->first();
        if ($revision === null || $history->filter(fn ($row): bool => $row->effective_at->equalTo($revision->effective_at))
            ->pluck('new_cost')->unique()->count() !== 1) {
            $this->invalid('An unambiguous effective standard-cost revision is required for every transfer variant.');
        }

        return ['unit_cost' => (string) $revision->new_cost, 'revision_id' => (int) $revision->id];
    }

    /**
     * @return Collection<int, FifoLayer>
     */
    private function layers(ProductVariant $variant, int $locationId): Collection
    {
        return FifoLayer::query()->where('variant_id', $variant->id)
            ->whereHas('stockMovement', fn ($query) => $query->where('location_id', $locationId))
            ->with('stockMovement')->orderByRaw('COALESCE(available_at, received_at)')->orderBy('id')->lockForUpdate()->get();
    }

    private function assertState(InventoryCostState $state, ?StockBalance $balance, ?InventoryCostEntry $latestEntry, string $method): void
    {
        if (($balance === null && ! BigDecimal::of($state->on_hand_qty)->isZero())
            || ($balance !== null && ! BigDecimal::of($state->on_hand_qty)->isEqualTo($balance->quantity_on_hand))
            || ($latestEntry?->method !== null && $latestEntry->method !== $method)
            || BigDecimal::of($state->on_hand_qty)->isNegative() || BigDecimal::of($state->total_value)->isNegative()
            || BigDecimal::of($state->avg_cost)->isNegative()
            || (BigDecimal::of($state->on_hand_qty)->isZero()
                && (! BigDecimal::of($state->total_value)->isZero() || ! BigDecimal::of($state->avg_cost)->isZero()))) {
            $this->invalid('Physical stock and location valuation disagree. Reconcile before transfer posting.');
        }
    }

    private function assertFifoLayers(Collection $layers, InventoryCostState $state): void
    {
        $quantity = BigDecimal::zero();
        $value = BigDecimal::zero();
        foreach ($layers as $layer) {
            $remaining = BigDecimal::of($layer->remaining_qty);
            if ($remaining->isNegative() || $remaining->isGreaterThan($layer->quantity)
                || BigDecimal::of($layer->unit_cost)->isNegative() || ! BigDecimal::of($layer->quantity)->isPositive()) {
                $this->invalid('FIFO layers require reconciliation before transfer posting.');
            }
            $quantity = $quantity->plus($remaining);
            $value = $value->plus(app(FifoLayerValue::class)->remaining($layer));
        }
        if (! $quantity->isEqualTo($state->on_hand_qty) || ! $value->isEqualTo($state->total_value)) {
            $this->invalid('FIFO layers and location valuation disagree. Reconcile before transfer posting.');
        }
    }

    /**
     * @param  Collection<int, FifoLayer>  $layers
     * @return array{0: BigDecimal, 1: list<array{fifo_layer_id: int, quantity: string, unit_cost: string, total_cost: string}>}
     */
    private function consumeLayers(Collection $layers, BigDecimal $quantity): array
    {
        $remaining = $quantity;
        $value = BigDecimal::zero();
        $parts = [];
        foreach ($layers as $layer) {
            if ($remaining->isZero()) {
                break;
            }
            $available = BigDecimal::of($layer->remaining_qty);
            if ($available->isZero()) {
                continue;
            }
            $used = $remaining->isLessThan($available) ? $remaining : $available;
            $partValue = app(FifoLayerValue::class)->consume($layer, $used);
            $parts[] = ['fifo_layer_id' => (int) $layer->id, 'quantity' => (string) $used->toScale(4),
                'unit_cost' => $layer->unit_cost, 'total_cost' => (string) $partValue->toScale(4)];
            $value = $value->plus($partValue);
            $remaining = $remaining->minus($used);
        }
        if (! $remaining->isZero()) {
            $this->invalid('FIFO layers cannot cover this transfer.');
        }

        return [$value, $parts];
    }

    private function value(BigDecimal $quantity, string $unitCost): BigDecimal
    {
        return $quantity->multipliedBy($unitCost)->toScale(4, RoundingMode::HalfUp);
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() === 0 || $this->authorizedCommands !== []) {
            throw new \LogicException('Stock transfer valuation requires its owning transfer transaction.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['valuation' => __($message)]);
    }
}
