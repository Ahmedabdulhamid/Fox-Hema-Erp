<?php

namespace App\Modules\InventoryCosting\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\Inventory\Data\StockPostingCommand;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnCostAllocation;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Sales\Services\SalesReturnApprovalService;
use App\Modules\Sales\Services\SalesReturnPostingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesReturnValuationService
{
    private array $authorizedCommands = [];

    private ?int $actorId = null;

    public function authorizesCommand(StockPostingCommand $command, User $actor): bool
    {
        return DB::transactionLevel() > 0 && $this->actorId === (int) $actor->id
            && ($this->authorizedCommands[spl_object_id($command)] ?? null) === $command;
    }

    public function post(SalesReturn $return, User $actor, CarbonInterface $postedAt): void
    {
        if (DB::transactionLevel() === 0 || $return->status !== 'approved' || $this->authorizedCommands !== []
            || $return->costAllocations()->exists()) {
            throw new \LogicException('Return valuation requires the unchanged approved document transaction.');
        }
        app(SalesAccess::class)->authorize($actor, SalesPermissions::RETURNS_POST, (int) $return->location_id);
        app(SalesAccess::class)->authorize($actor, SalesPermissions::RETURNS_VIEW, (int) $return->location_id);
        app(InventoryAccess::class)->authorizePosting($actor, (int) $return->location_id);
        abort_unless(app(ModuleAccess::class)->isActive('inventory_costing'), 403);
        app(SalesReturnApprovalService::class)->verifyApproved($return);
        $items = $return->items()->with(['variant.product', 'salesDeliveryItem.stockMovement.inventoryCostEntry.fifoLayerConsumptions.fifoLayer'])->orderBy('id')->get();
        $restockIds = $items->where('disposition', 'restock')->pluck('variant_id')->unique()->sort()->values()->all();
        if ($restockIds !== []) {
            app(StockIssueValuationService::class)->verifyCurrent($restockIds, (int) $return->location_id);
        }
        $states = [];
        $revisions = [];
        foreach ($restockIds as $variantId) {
            $item = $items->firstWhere('variant_id', $variantId);
            $state = InventoryCostState::where('variant_id', $variantId)->where('location_id', $return->location_id)->lockForUpdate()->firstOrFail();
            $method = InventoryValuationSetting::where('category_id', $item->variant->product->category_id)->lockForUpdate()->value('costing_method');
            $original = $item->salesDeliveryItem->stockMovement->inventoryCostEntry;
            if ($method !== $original->method || InventoryCostState::where('variant_id', $variantId)->whereNull('location_id')->lockForUpdate()->exists()) {
                $this->invalid();
            }
            $last = InventoryCostEntry::whereHas('stockMovement', fn ($q) => $q->where('variant_id', $variantId)->where('location_id', $return->location_id))->orderByDesc('id')->lockForUpdate()->first();
            if ($last !== null && $last->method !== $method) {
                $this->invalid();
            }
            if ($method === 'standard') {
                $standard = InventoryStandardCost::where('variant_id', $variantId)->lockForUpdate()->first();
                $history = $standard?->revisions()->where('effective_at', '<=', $postedAt)->orderByDesc('effective_at')->orderByDesc('id')->lockForUpdate()->get();
                $revision = $history?->first();
                if ($revision === null || $history->filter(fn ($r) => $r->effective_at->equalTo($revision->effective_at))->pluck('new_cost')->unique()->count() !== 1
                    || ! $this->value($state->on_hand_qty, $revision->new_cost)->isEqualTo($state->total_value)) {
                    $this->invalid();
                }
                $revisions[$variantId] = $revision;
            }
            $states[$variantId] = $state;
        }
        $budgets = [];
        foreach ($items as $item) {
            $entry = $item->salesDeliveryItem->stockMovement->inventoryCostEntry;
            if (! isset($budgets[$entry->id])) {
                $this->verifyBudget($entry);
                $budgets[$entry->id] = $this->budget($entry);
            }
            $remaining = BigDecimal::of($item->quantity);
            foreach ($budgets[$entry->id] as &$slice) {
                if ($remaining->isZero()) {
                    break;
                }
                $available = BigDecimal::of($slice['quantity'])->minus($slice['used_quantity']);
                if ($available->isZero()) {
                    continue;
                }
                $quantity = BigDecimal::min($remaining, $available);
                $newUsed = $slice['used_quantity']->plus($quantity);
                $target = $newUsed->isEqualTo($slice['quantity']) ? BigDecimal::of($slice['cost'])
                    : BigDecimal::of($slice['cost'])->multipliedBy($newUsed)->dividedBy($slice['quantity'], 4, RoundingMode::HalfUp);
                $originalCost = $target->minus($slice['used_cost']);
                if ($originalCost->isNegative()) {
                    $this->invalid();
                }
                $carrying = $item->disposition === 'scrap' ? BigDecimal::zero() : $originalCost;
                $currentRevision = null;
                if ($item->disposition === 'restock' && $entry->method === 'standard') {
                    $currentRevision = $revisions[$item->variant_id];
                    $state = $states[$item->variant_id];
                    $carrying = $this->value((string) BigDecimal::of($state->on_hand_qty)->plus($quantity), $currentRevision->new_cost)->minus($state->total_value);
                }
                $loss = $item->disposition === 'scrap' ? $originalCost : BigDecimal::zero();
                $variance = $originalCost->minus($carrying)->minus($loss);
                $identity = hash('sha256', 'sales-return-item:'.$item->uuid.':'.$slice['key']);
                $allocationId = DB::table('sales_return_cost_allocations')->insertGetId([
                    'uuid' => (string) Str::uuid7(), 'slice_key' => $slice['key'], 'operation_identity' => $identity,
                    'sales_return_id' => $return->id, 'sales_return_item_id' => $item->id, 'sales_delivery_item_id' => $item->sales_delivery_item_id,
                    'original_cost_entry_id' => $entry->id, 'fifo_consumption_id' => $slice['consumption_id'],
                    'location_id' => $return->location_id, 'variant_id' => $item->variant_id, 'method' => $entry->method, 'disposition' => $item->disposition,
                    'quantity' => (string) $quantity->toScale(4), 'original_unit_cost' => $slice['unit_cost'], 'original_cost' => (string) $originalCost->toScale(4),
                    'carrying_value' => (string) $carrying->toScale(4), 'return_variance' => (string) $variance->toScale(4), 'loss_value' => (string) $loss->toScale(4),
                    'original_standard_revision_id' => $entry->standard_cost_revision_id, 'current_standard_revision_id' => $currentRevision?->id,
                    'posted_by' => $actor->id, 'posted_at' => $postedAt, 'created_at' => $postedAt, 'updated_at' => $postedAt,
                ]);
                $allocation = SalesReturnCostAllocation::findOrFail($allocationId);
                if ($item->disposition === 'restock') {
                    $unitCost = $currentRevision?->new_cost ?? $slice['unit_cost'];
                    $command = new StockPostingCommand($item->variant, $return->location, StockMovementType::Return,
                        $allocation->quantity, 'sales-return-restock:'.$identity, reference: $allocation, valuationUnitCost: $unitCost);
                    $this->actorId = (int) $actor->id;
                    $this->authorizedCommands[spl_object_id($command)] = $command;
                    try {
                        $movement = app(InventoryPostingService::class)->post($command, $actor);
                    } finally {
                        $this->actorId = null;
                        $this->authorizedCommands = [];
                    }
                    DB::table('sales_return_cost_allocations')->where('id', $allocationId)->update(['stock_movement_id' => $movement->id]);
                    DB::table('inventory_cost_entries')->insert([
                        'uuid' => (string) Str::uuid7(), 'stock_movement_id' => $movement->id, 'method' => $entry->method, 'entry_type' => 'reversal',
                        'quantity' => $allocation->quantity, 'unit_cost' => $unitCost, 'total_cost' => $allocation->carrying_value,
                        'standard_cost_revision_id' => $currentRevision?->id, 'created_at' => $postedAt, 'updated_at' => $postedAt,
                    ]);
                    if ($entry->method === 'fifo') {
                        $source = FifoLayer::findOrFail($slice['layer_id']);
                        DB::table('fifo_layers')->insert([
                            'uuid' => (string) Str::uuid7(), 'variant_id' => $item->variant_id, 'stock_movement_id' => $movement->id,
                            'quantity' => $allocation->quantity, 'remaining_qty' => $allocation->quantity, 'unit_cost' => $unitCost,
                            'received_at' => $source->getRawOriginal('received_at'), 'source_layer_id' => $source->id,
                            'initial_value' => $allocation->carrying_value, 'remaining_value' => $allocation->carrying_value,
                            'available_at' => $postedAt, 'return_cost_allocation_id' => $allocationId, 'created_at' => $postedAt, 'updated_at' => $postedAt,
                        ]);
                    }
                    $state = $states[$item->variant_id];
                    $nextQuantity = BigDecimal::of($state->on_hand_qty)->plus($quantity);
                    $nextValue = BigDecimal::of($state->total_value)->plus($carrying);
                    $state->fill(['on_hand_qty' => (string) $nextQuantity->toScale(4), 'total_value' => (string) $nextValue->toScale(4),
                        'avg_cost' => (string) $nextValue->dividedBy($nextQuantity, 6, RoundingMode::HalfUp)])->save();
                } elseif ($item->disposition === 'damaged') {
                    $custodyUuid = (string) Str::uuid7();
                    app(DocumentNumberService::class)->allocate((int) $return->location_id, 'QC', $custodyUuid);
                    DB::table('quarantine_receipts')->insert([
                        'uuid' => $custodyUuid, 'return_cost_allocation_id' => $allocationId, 'location_id' => $return->location_id,
                        'variant_id' => $item->variant_id, 'quantity' => $allocation->quantity, 'original_cost' => $allocation->original_cost,
                        'provisional_carrying_value' => $allocation->original_cost, 'condition_notes' => $item->notes ?? $return->reason ?? __('returns.pending_inspection'),
                        'responsible_user_id' => $actor->id, 'received_by' => $actor->id, 'received_at' => $postedAt, 'created_at' => $postedAt, 'updated_at' => $postedAt,
                    ]);
                } else {
                    $reason = trim($item->notes ?? '') ?: trim($return->reason ?? '');
                    if ($reason === '') {
                        throw ValidationException::withMessages(['record' => __('returns.scrap_reason_required')]);
                    }
                    DB::table('sales_return_scrap_receipts')->insert([
                        'uuid' => (string) Str::uuid7(), 'return_cost_allocation_id' => $allocationId, 'location_id' => $return->location_id,
                        'variant_id' => $item->variant_id, 'quantity' => $allocation->quantity, 'original_cost' => $allocation->original_cost,
                        'operational_loss_value' => $allocation->original_cost, 'reason' => $reason, 'accepted_by' => $actor->id, 'accepted_at' => $postedAt,
                        'created_at' => $postedAt, 'updated_at' => $postedAt,
                    ]);
                }
                $slice['used_quantity'] = $newUsed;
                $slice['used_cost'] = $target;
                $remaining = $remaining->minus($quantity);
            }
            unset($slice);
            if (! $remaining->isZero()) {
                $this->invalid();
            }
        }
    }

    /** Verify exact cumulative historical allocations, including all dispositions and partial-return residuals. */
    public function verifyBudget(InventoryCostEntry $entry): void
    {
        $budgets = $this->budget($entry);
        $rows = DB::table('sales_return_cost_allocations')->where('original_cost_entry_id', $entry->id)->orderBy('id')->lockForUpdate()->get();
        $quantities = [];
        $costs = [];
        foreach ($rows as $row) {
            app(SalesReturnPostingService::class)->verifyFingerprint(SalesReturn::findOrFail($row->sales_return_id));
            $slice = $budgets[$row->slice_key] ?? null;
            if ($slice === null || $row->method !== $entry->method || ! BigDecimal::of($row->quantity)->isPositive()) {
                $this->invalid();
            }
            $quantities[$row->slice_key] = ($quantities[$row->slice_key] ?? BigDecimal::zero())->plus($row->quantity);
            $costs[$row->slice_key] = ($costs[$row->slice_key] ?? BigDecimal::zero())->plus($row->original_cost);
            $expected = BigDecimal::of($slice['cost'])->multipliedBy($quantities[$row->slice_key])->dividedBy($slice['quantity'], 4, RoundingMode::HalfUp);
            if ($quantities[$row->slice_key]->isGreaterThan($slice['quantity']) || ! $costs[$row->slice_key]->isEqualTo($expected)
                || ! BigDecimal::of($row->original_cost)->isEqualTo(BigDecimal::of($row->carrying_value)->plus($row->return_variance)->plus($row->loss_value))) {
                $this->invalid();
            }
        }
        $deliveryItem = $entry->stockMovement->salesDeliveryItem;
        if ($deliveryItem === null) {
            $this->invalid();
        }
        $postedQuantity = DB::table('sales_return_items as i')->join('sales_returns as r', 'r.id', '=', 'i.sales_return_id')
            ->where('i.sales_delivery_item_id', $deliveryItem->id)->where('r.status', 'posted')->sum('i.quantity');
        $allocatedPosted = DB::table('sales_return_cost_allocations as a')->join('sales_returns as r', 'r.id', '=', 'a.sales_return_id')
            ->where('a.original_cost_entry_id', $entry->id)->where('r.status', 'posted')->sum('a.quantity');
        if (! BigDecimal::of($postedQuantity)->isEqualTo($allocatedPosted)) {
            $this->invalid();
        }
    }

    private function budget(InventoryCostEntry $entry): array
    {
        if ($entry->entry_type !== 'issue' || ! BigDecimal::of($entry->quantity)->isNegative() || BigDecimal::of($entry->total_cost)->isPositive()) {
            $this->invalid();
        }
        $slices = [];
        if ($entry->method === 'fifo') {
            foreach ($entry->fifoLayerConsumptions()->orderBy('id')->lockForUpdate()->get() as $part) {
                $slices['fifo:'.$part->id] = ['key' => 'fifo:'.$part->id, 'consumption_id' => $part->id, 'layer_id' => $part->fifo_layer_id,
                    'quantity' => $part->quantity, 'cost' => $part->total_cost, 'unit_cost' => $part->unit_cost];
            }
        } else {
            $slices['issue:'.$entry->id] = ['key' => 'issue:'.$entry->id, 'consumption_id' => null, 'layer_id' => null,
                'quantity' => (string) BigDecimal::of($entry->quantity)->negated()->toScale(4),
                'cost' => (string) BigDecimal::of($entry->total_cost)->negated()->toScale(4), 'unit_cost' => $entry->unit_cost];
        }
        foreach ($slices as &$slice) {
            $rows = DB::table('sales_return_cost_allocations')->where('original_cost_entry_id', $entry->id)->where('slice_key', $slice['key'])->lockForUpdate()->get();
            $slice['used_quantity'] = $rows->reduce(fn ($s, $r) => $s->plus($r->quantity), BigDecimal::zero());
            $slice['used_cost'] = $rows->reduce(fn ($s, $r) => $s->plus($r->original_cost), BigDecimal::zero());
            if ($slice['used_quantity']->isGreaterThan($slice['quantity']) || $slice['used_cost']->isGreaterThan($slice['cost'])) {
                $this->invalid();
            }
        }

        return $slices;
    }

    private function value(string $quantity, string $unitCost): BigDecimal
    {
        return BigDecimal::of($quantity)->multipliedBy($unitCost)->toScale(4, RoundingMode::HalfUp);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['valuation' => __('returns.return_valuation_invalid')]);
    }
}
