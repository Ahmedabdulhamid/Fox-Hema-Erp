<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Inventory\Data\StockPostingCommand;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\InventoryCosting\Services\ReceiptValuationService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\PurchaseReceiptItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseReceiptService
{
    public function __construct(
        private readonly PurchasingAccess $access,
        private readonly PurchaseOrderCalculator $calculator,
        private readonly InventoryPostingService $inventory,
        private readonly ReceiptValuationService $valuation,
    ) {}

    /** Explicit preparation for pre-feature orders or cancelled drafts; never a bulk backfill. */
    public function prepare(User $actor, PurchaseOrder $order): ?PurchaseReceipt
    {
        return DB::transaction(function () use ($actor, $order): ?PurchaseReceipt {
            ModuleRegistryLock::acquire();
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, PurchasingPermissions::RECEIPTS_CREATE, (int) $order->location_id);
            $this->receivable($order);

            return $this->prepareOutstanding($order);
        }, 3);
    }

    /** Automatic confirmation side effect uses the confirming user's PO authority. */
    public function prepareAfterConfirmation(User $actor, PurchaseOrder $order): PurchaseReceipt
    {
        if (DB::connection()->transactionLevel() === 0) {
            throw new \LogicException('Initial receipt preparation belongs to the PO confirmation transaction.');
        }
        $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
        $this->access->authorize($actor, PurchasingPermissions::ORDERS_CONFIRM, (int) $order->location_id);
        $this->access->authorize($actor, PurchasingPermissions::ORDERS_VIEW, (int) $order->location_id);
        $this->receivable($order);
        $key = hash('sha256', 'purchase-order:'.$order->uuid.':initial-receipt');
        $existing = $order->receipts()->where('preparation_key', $key)->lockForUpdate()->first();
        if ($existing !== null) {
            return $existing;
        }
        if ($order->receipts()->exists()) {
            $this->invalid('receipt', 'Existing receiving documents require explicit receipt preparation.');
        }

        return $this->createDraft($order, $key);
    }

    public function update(User $actor, PurchaseReceipt $receipt, array $data, string $revision): PurchaseReceipt
    {
        return DB::transaction(function () use ($actor, $receipt, $data, $revision): PurchaseReceipt {
            [$order, $receipt] = $this->lock($receipt);
            $this->authorize($actor, PurchasingPermissions::RECEIPTS_UPDATE, (int) $order->location_id);
            $this->draft($receipt);
            $this->receivable($order);
            $this->checkRevision($receipt, $revision);
            foreach (array_keys($data) as $field) {
                if (! in_array($field, ['items', 'notes', 'received_at'], true)) {
                    $this->invalid($field, 'Receipt identity, destination and status are controlled by receipt operations.');
                }
            }
            Validator::make($data, ['items' => ['required', 'array', 'min:1', 'max:200'],
                'items.*.id' => ['required', 'integer', 'distinct'], 'notes' => ['nullable', 'string', 'max:16000'],
                'received_at' => ['nullable', 'date', 'before_or_equal:now']])->validate();
            $items = $receipt->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($items->contains(fn (PurchaseReceiptItem $item): bool => $item->stock_movement_id !== null)) {
                $this->invalid('items', 'Draft receipts linked to stock movements require reconciliation.');
            }
            if ($items->count() !== count($data['items'])) {
                $this->invalid('items', 'Save all expected receipt lines, using zero for lines not received.');
            }
            foreach (array_values($data['items']) as $index => $line) {
                $item = $items->get($line['id']);
                if ($item === null || array_diff(array_keys($line), ['id', 'quantity', 'unit_cost']) !== []) {
                    $this->invalid('items.'.$index, 'Receipt line identity is invalid or includes protected fields.');
                }
                $item->fill(['quantity' => $this->calculator->decimal($line['quantity'] ?? null, 'items.'.$index.'.quantity', 18, 4),
                    'unit_cost' => $this->calculator->decimal($line['unit_cost'] ?? null, 'items.'.$index.'.unit_cost', 18, 6)])->save();
            }
            $receipt->fill(Arr::only($data, ['notes', 'received_at']))->save();

            return $receipt->refresh()->load('items');
        }, 3);
    }

    public function post(User $actor, PurchaseReceipt $receipt, string $revision): PurchaseReceipt
    {
        return DB::transaction(function () use ($actor, $receipt, $revision): PurchaseReceipt {
            [$order, $receipt] = $this->lock($receipt);
            $this->authorize($actor, PurchasingPermissions::RECEIPTS_POST, (int) $order->location_id);
            app(InventoryAccess::class)->authorizePosting($actor, (int) $order->location_id);
            if ($receipt->status === 'posted') {
                return $receipt->load('items.stockMovement.inventoryCostEntry');
            }
            $this->draft($receipt);
            $this->receivable($order);
            $this->checkRevision($receipt, $revision);
            $orderItems = $order->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $items = $receipt->items()->with('purchaseOrderItem.variant.product')->orderBy('id')->lockForUpdate()->get();
            if ($items->isEmpty() || $items->count() > 200) {
                $this->invalid('items', 'A receipt requires between one and 200 expected lines.');
            }
            $received = $this->received($order, true);
            $positive = [];
            foreach ($items as $item) {
                $line = $orderItems->get($item->purchase_order_item_id);
                if ($line === null || (int) $item->purchase_order_id !== (int) $order->id || $item->stock_movement_id !== null) {
                    $this->invalid('items', 'Receipt references are inconsistent or already linked to stock.');
                }
                $quantity = BigDecimal::of($this->calculator->decimal($item->quantity, 'quantity', 18, 4));
                if ($line->variant->isUnallocated() && ! $quantity->isEqualTo($quantity->toScale(0, RoundingMode::Down))) {
                    $this->invalid('quantity', 'Unallocated stock must be received as individual whole pieces.');
                }
                if ($item->expected_quantity === null) {
                    $this->invalid('items', 'Legacy draft receipt lines require review; cancel unlinked drafts and prepare a replacement.');
                }
                $this->calculator->decimal($item->unit_cost, 'unit_cost', 18, 6);
                if ($quantity->plus($received[$line->id] ?? '0')->isGreaterThan($line->quantity)) {
                    $this->invalid('quantity', 'Received quantity exceeds the outstanding ordered quantity.');
                }
                if ($quantity->isPositive()) {
                    $positive[] = $item;
                }
            }
            if ($positive === []) {
                $this->invalid('quantity', 'Enter a positive received quantity on at least one line.');
            }
            usort($positive, fn (PurchaseReceiptItem $a, PurchaseReceiptItem $b): int => [$a->purchaseOrderItem->variant_id, $a->id] <=> [$b->purchaseOrderItem->variant_id, $b->id]);
            $postedAt = now();
            $plans = $this->valuation->prepare($positive, $postedAt);
            $commands = array_map(fn (PurchaseReceiptItem $item): StockPostingCommand => new StockPostingCommand(
                $item->purchaseOrderItem->variant, $order->location, StockMovementType::Purchase, $item->quantity,
                'purchase-receipt-item:'.$item->uuid.':validate', reference: $item,
                valuationUnitCost: $plans[$item->id]['unit_cost'],
            ), $positive);
            $movements = $this->inventory->postMany($commands, $actor);
            $this->valuation->post($positive, $movements, $plans, (int) $order->location_id, $postedAt);
            foreach ($positive as $index => $item) {
                $item->stock_movement_id = $movements[$index]->id;
                $item->save();
            }
            $receipt->fill(['status' => 'posted', 'posted_at' => $postedAt, 'posted_by' => $actor->id,
                'received_at' => $receipt->received_at ?? $postedAt, 'received_by' => $actor->id])->save();
            $received = $this->received($order, true);
            $complete = $orderItems->every(fn (PurchaseOrderItem $item): bool => BigDecimal::of($received[$item->id] ?? '0')->isEqualTo($item->quantity));
            $order->status = $complete ? 'received' : 'partially_received';
            $order->save();
            if (! $complete) {
                $this->prepareOutstanding($order, $receipt);
            }

            return $receipt->refresh()->load('items.stockMovement.inventoryCostEntry');
        }, 3);
    }

    public function cancel(User $actor, PurchaseReceipt $receipt, string $revision): PurchaseReceipt
    {
        return DB::transaction(function () use ($actor, $receipt, $revision): PurchaseReceipt {
            [$order, $receipt] = $this->lock($receipt);
            $this->authorize($actor, PurchasingPermissions::RECEIPTS_CANCEL, (int) $order->location_id);
            if ($receipt->status === 'cancelled') {
                return $receipt;
            }
            $this->draft($receipt);
            $this->checkRevision($receipt, $revision);
            if ($receipt->items()->whereNotNull('stock_movement_id')->exists()) {
                $this->invalid('items', 'Draft receipts linked to stock movements require reconciliation.');
            }
            $receipt->status = 'cancelled';
            $receipt->save();

            return $receipt->refresh();
        }, 3);
    }

    public function revision(PurchaseReceipt $receipt): string
    {
        return hash('sha256', json_encode([$receipt->getAttributes(), $receipt->items()->orderBy('id')->get()->map->getAttributes()->all()], JSON_THROW_ON_ERROR));
    }

    /** @return array<int, string> Posted quantities only; no billing inference. */
    public function received(PurchaseOrder $order, bool $lock = false): array
    {
        $query = $order->receiptItems()->whereHas('purchaseReceipt', fn ($query) => $query->where('status', 'posted'))->orderBy('id');
        $items = ($lock ? $query->lockForUpdate() : $query)->get();
        $quantities = [];
        foreach ($items as $item) {
            if ($lock && BigDecimal::of($item->quantity)->isPositive()
                && ($item->stock_movement_id === null || $item->stockMovement?->inventoryCostEntry === null)) {
                $this->invalid('receipt', 'Historical posted receipts lack linked inventory valuation. Reconcile before receiving.');
            }
            $quantities[$item->purchase_order_item_id] = (string) BigDecimal::of($quantities[$item->purchase_order_item_id] ?? '0')->plus($item->quantity)->toScale(4);
        }

        return $quantities;
    }

    /** @return array{ordered: string, received: string, remaining: string, receipt_count: int} */
    public function summary(PurchaseOrder $order): array
    {
        $ordered = BigDecimal::zero();
        $received = BigDecimal::zero();
        $posted = $this->received($order);
        foreach ($order->items as $item) {
            $ordered = $ordered->plus($item->quantity);
            $received = $received->plus($posted[$item->id] ?? '0');
        }

        return ['ordered' => (string) $ordered->toScale(4), 'received' => (string) $received->toScale(4),
            'remaining' => (string) $ordered->minus($received)->toScale(4), 'receipt_count' => $order->receipts()->count()];
    }

    /** @return array{PurchaseOrder, PurchaseReceipt} */
    private function lock(PurchaseReceipt $receipt): array
    {
        ModuleRegistryLock::acquire();
        $orderId = PurchaseReceipt::whereKey($receipt->id)->value('purchase_order_id');
        $order = PurchaseOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
        $receipt = $order->receipts()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
        if ((int) $receipt->location_id !== (int) $order->location_id) {
            $this->invalid('location_id', 'Receipt destination must match its confirmed purchase order.');
        }

        return [$order, $receipt];
    }

    private function authorize(User $actor, string $permission, int $locationId): void
    {
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        $this->access->authorize($actor, PurchasingPermissions::RECEIPTS_VIEW, $locationId);
        $this->access->authorize($actor, $permission, $locationId);
    }

    private function receivable(PurchaseOrder $order): void
    {
        if (! in_array($order->status, ['confirmed', 'partially_received', 'received'], true)) {
            $this->invalid('status', 'Only confirmed purchase orders can be received.');
        }
    }

    private function draft(PurchaseReceipt $receipt): void
    {
        if ($receipt->status !== 'draft') {
            $this->invalid('status', 'Only draft receipts can be edited, validated or cancelled.');
        }
    }

    private function checkRevision(PurchaseReceipt $receipt, string $revision): void
    {
        if (! hash_equals($this->revision($receipt), $revision)) {
            $this->invalid('revision', 'This receipt changed. Reload before continuing.');
        }
    }

    private function prepareOutstanding(PurchaseOrder $order, ?PurchaseReceipt $predecessor = null): ?PurchaseReceipt
    {
        $pending = $order->receipts()->where('status', 'draft')->orderBy('id')->lockForUpdate()->first();
        if ($pending !== null) {
            if ((int) $pending->location_id !== (int) $order->location_id
                || $pending->items()->whereNull('expected_quantity')->exists() || ! $pending->items()->exists()) {
                $this->invalid('receipt', 'Legacy draft receipt lines require review; cancel unlinked drafts and prepare a replacement.');
            }

            return $pending;
        }
        $remaining = $this->outstanding($order);
        if ($remaining->isEmpty()) {
            return null;
        }
        $lastId = $order->receipts()->max('id') ?? 0;
        $key = hash('sha256', 'purchase-order:'.$order->uuid.':outstanding-after:'.$lastId);

        return $this->createDraft($order, $key, $predecessor, $remaining);
    }

    /** @return Collection<int, PurchaseOrderItem> Positive remaining lines, with a transient remaining attribute. */
    private function outstanding(PurchaseOrder $order): Collection
    {
        $received = $this->received($order, true);

        return $order->items()->orderBy('id')->lockForUpdate()->get()->filter(function (PurchaseOrderItem $item) use ($received): bool {
            $item->setAttribute('remaining', (string) BigDecimal::of($item->quantity)->minus($received[$item->id] ?? '0')->toScale(4));
            if (BigDecimal::of($item->remaining)->isNegative()) {
                $this->invalid('quantity', 'Historical receipts exceed ordered quantities. Reconcile before preparing receipts.');
            }

            return BigDecimal::of($item->remaining)->isPositive();
        });
    }

    private function createDraft(PurchaseOrder $order, string $key, ?PurchaseReceipt $predecessor = null, ?Collection $remaining = null): PurchaseReceipt
    {
        $remaining ??= $this->outstanding($order);
        if ($remaining->isEmpty()) {
            $this->invalid('items', 'There are no outstanding quantities to receive.');
        }
        $uuid = (string) Str::uuid7();
        $receipt = new PurchaseReceipt(['purchase_order_id' => $order->id, 'location_id' => $order->location_id,
            'receipt_number' => app(DocumentNumberService::class)->allocate((int) $order->location_id, 'GRN', $uuid), 'status' => 'draft']);
        $receipt->uuid = $uuid;
        $receipt->preparation_key = $key;
        $receipt->backorder_of_id = $predecessor?->id;
        $receipt->save();
        foreach ($remaining as $item) {
            $receipt->items()->create(['purchase_order_id' => $order->id, 'purchase_order_item_id' => $item->id,
                'expected_quantity' => $item->remaining, 'quantity' => '0.0000', 'unit_cost' => $item->unit_price]);
        }

        return $receipt->load('items');
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
