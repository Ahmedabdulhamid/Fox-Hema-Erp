<?php

namespace App\Modules\Sales\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\InventoryCosting\Services\StockIssueValuationService;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesOrder;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesDeliveryPostingService
{
    public function __construct(private readonly SalesAccess $access, private readonly SalesDeliveryService $drafts,
        private readonly SalesFulfillmentService $fulfillment, private readonly SalesReservationService $reservations,
        private readonly StockIssueValuationService $valuation, private readonly InventoryPostingService $inventory) {}

    public function post(User $actor, SalesDelivery $delivery, string $revision): SalesDelivery
    {
        return DB::transaction(function () use ($actor, $delivery, $revision): SalesDelivery {
            ModuleRegistryLock::acquire();
            $orderId = SalesDelivery::whereKey($delivery->id)->value('sales_order_id');
            $order = SalesOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
            app(SalesDocumentLocks::class)->acquire($order);
            $delivery = $order->deliveries()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, SalesPermissions::DELIVERIES_VIEW, (int) $order->location_id);
            $this->access->authorize($actor, SalesPermissions::DELIVERIES_POST, (int) $order->location_id);
            app(InventoryAccess::class)->authorizePosting($actor, (int) $order->location_id);
            abort_unless(app(ModuleAccess::class)->isActive('inventory_costing'), 403);
            abort_unless(Location::whereKey($order->location_id)->where('type', 'branch')->where('is_active', true)->sharedLock()->exists(), 403);
            if ((int) $delivery->location_id !== (int) $order->location_id || $order->confirmed_at === null
                || $order->confirmation_payload_hash === null || $order->cancelled_at !== null || $order->cancelled_by !== null
                || $order->cancellation_payload_hash !== null
                || ! hash_equals($order->confirmation_payload_hash, app(SalesOrderConfirmationService::class)->fingerprint($order))) {
                $this->invalid('Sales order confirmation or delivery ownership is inconsistent.');
            }
            $orderItems = $order->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $items = $delivery->items()->with('salesOrderItem.variant.product')->orderBy('id')->lockForUpdate()->get();
            if ($items->isEmpty() || $items->count() > 200) {
                $this->invalid('A delivery requires between one and 200 valid order lines.');
            }
            if ($delivery->status === 'posted') {
                if ($delivery->posted_at === null || $delivery->posted_by === null || $delivery->posting_payload_hash === null
                    || $delivery->posting_evidence_hash === null || $delivery->posting_revision === null
                    || ! hash_equals($delivery->posting_payload_hash, $this->fingerprint($delivery))
                    || ! in_array($revision, [$delivery->posting_revision, $this->drafts->revision($delivery)], true)) {
                    $this->invalid('Delivery posting evidence conflicts with this request. Reload and review the document.');
                }
                $this->reservations->verifyFulfillment($order);
                $this->valuation->verifyCurrent($items->map(fn ($item): int => (int) $item->salesOrderItem->variant_id)->unique()->values()->all(), (int) $order->location_id);
                $this->verifyEvidence($delivery);
                if (! hash_equals($delivery->posting_evidence_hash, $this->evidenceHash($delivery))) {
                    $this->invalid('Delivery posting evidence conflicts with this request. Reload and review the document.');
                }
                $this->assertFulfillment($order);

                return $delivery->load(['items.stockMovement.inventoryCostEntry', 'items.reservationConsumption', 'postedBy']);
            }
            if ($delivery->status !== 'draft' || $delivery->posted_at !== null || $delivery->posted_by !== null
                || $delivery->posting_payload_hash !== null || $delivery->posting_evidence_hash !== null || $delivery->posting_revision !== null
                || $delivery->cancelled_at !== null || $delivery->cancelled_by !== null || $delivery->cancellation_payload_hash !== null
                || ! in_array($order->status, ['confirmed', 'partially_fulfilled'], true)
                || ! hash_equals($this->drafts->revision($delivery), $revision)) {
                $this->invalid('Only an unchanged draft delivery on a confirmed or partially fulfilled order can be posted.');
            }
            $this->assertFulfillment($order);
            $remaining = $this->fulfillment->quantities($order, true);
            $variants = ProductVariant::whereKey($orderItems->pluck('variant_id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
            $products = Product::whereKey($variants->pluck('product_id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
            $seen = [];
            foreach ($items as $item) {
                $line = $orderItems->get($item->sales_order_item_id);
                $variant = $line === null ? null : $variants->get($line->variant_id);
                $product = $variant === null ? null : $products->get($variant->product_id);
                $quantity = BigDecimal::of(app(PurchaseOrderCalculator::class)->decimal($item->quantity, 'quantity', 18, 4));
                if ($line === null || (int) $item->sales_order_id !== (int) $order->id || isset($seen[$line->id])
                    || $variant === null || $variant->isUnallocated() || ! $variant->is_active || ! $product?->is_active || $product->status->value !== 'active'
                    || ! $quantity->isPositive() || $quantity->isGreaterThan($remaining[$line->id]['remaining'])
                    || $item->stock_movement_id !== null || $item->reservationConsumption()->exists()
                    || $item->invoiceMatches()->exists() || $item->returnItems()->exists()) {
                    $this->invalid('Delivery lines are invalid, already linked, or exceed the remaining ordered quantity.');
                }
                $seen[$line->id] = true;
                $variant->setRelation('product', $product);
                $line->setRelation('variant', $variant);
                $item->setRelation('salesOrderItem', $line);
            }
            $items = $items->sortBy(fn ($item): array => [$item->salesOrderItem->variant_id, $item->id])->values()->all();
            [, $balances] = $this->reservations->verifyFulfillment($order);
            $openingAvailable = $balances->map(fn ($balance): string => $balance->quantity_available);
            $postedAt = now();
            $fingerprint = $this->fingerprint($delivery);
            $movements = $this->valuation->post($delivery, $items, $actor, $postedAt,
                fn () => $this->reservations->consume($order, $items, $actor, $postedAt));
            foreach ($items as $index => $item) {
                DB::table('sales_delivery_items')->where('id', $item->id)->update(['stock_movement_id' => $movements[$index]->id, 'updated_at' => $postedAt]);
            }
            DB::table('sales_deliveries')->where('id', $delivery->id)->update(['status' => 'posted', 'posted_at' => $postedAt,
                'posted_by' => $actor->id, 'posting_payload_hash' => $fingerprint, 'posting_revision' => $revision, 'updated_at' => $postedAt]);
            $summary = $this->fulfillment->quantities($order, true);
            $complete = collect($summary)->every(fn ($line): bool => BigDecimal::of($line['remaining'])->isZero());
            DB::table('sales_orders')->where('id', $order->id)->update(['status' => $complete ? 'fulfilled' : 'partially_fulfilled',
                'fulfillment_status' => $complete ? 'fulfilled' : 'partial', 'updated_at' => $postedAt]);
            $order->refresh();
            $delivery->refresh();
            $this->verifyEvidence($delivery);
            [, $closingBalances] = $this->reservations->verifyFulfillment($order);
            foreach ($closingBalances as $variantId => $balance) {
                if (! BigDecimal::of($openingAvailable->get($variantId))->isEqualTo($balance->quantity_available)) {
                    $this->invalid('Reserved delivery changed free stock availability unexpectedly.');
                }
            }
            $this->assertFulfillment($order);
            DB::table('sales_deliveries')->where('id', $delivery->id)->update(['posting_evidence_hash' => $this->evidenceHash($delivery)]);

            return $delivery->refresh()->load(['items.stockMovement.inventoryCostEntry', 'items.reservationConsumption', 'postedBy']);
        }, 3);
    }

    public function fingerprint(SalesDelivery $delivery): string
    {
        $header = [];
        foreach (['id', 'uuid', 'delivery_number', 'sales_order_id', 'location_id', 'created_by', 'delivered_at', 'notes', 'submission_payload_hash'] as $field) {
            $header[$field] = $delivery->getRawOriginal($field);
        }
        $items = $delivery->items()->orderBy('id')->get()->map(fn ($item): array => [
            'id' => $item->id, 'uuid' => $item->uuid, 'sales_order_id' => $item->sales_order_id,
            'sales_order_item_id' => $item->sales_order_item_id, 'quantity' => $item->quantity])->all();

        return hash('sha256', json_encode([$header, $items], JSON_THROW_ON_ERROR));
    }

    /** Read-only verification for downstream documents; does not reprice stock or consume reservations. */
    public function verifyPostedEvidence(SalesDelivery $delivery): void
    {
        if (DB::transactionLevel() === 0 || $delivery->status !== 'posted' || $delivery->posted_at === null
            || $delivery->posted_by === null || $delivery->posting_payload_hash === null || $delivery->posting_evidence_hash === null
            || ! hash_equals($delivery->posting_payload_hash, $this->fingerprint($delivery))) {
            $this->invalid('Delivery posting evidence is incomplete.');
        }
        $this->verifyEvidence($delivery);
        if (! hash_equals($delivery->posting_evidence_hash, $this->evidenceHash($delivery))) {
            $this->invalid('Delivery posting evidence conflicts with this request. Reload and review the document.');
        }
    }

    private function assertFulfillment(SalesOrder $order): void
    {
        $summary = collect($this->fulfillment->quantities($order, true));
        $complete = $summary->isNotEmpty() && $summary->every(fn ($line): bool => BigDecimal::of($line['remaining'])->isZero());
        $any = $summary->contains(fn ($line): bool => BigDecimal::of($line['delivered'])->isPositive());
        if ($order->status !== ($complete ? 'fulfilled' : ($any ? 'partially_fulfilled' : 'confirmed'))
            || $order->fulfillment_status !== ($complete ? 'fulfilled' : ($any ? 'partial' : 'unfulfilled'))) {
            $this->invalid('Order fulfillment status disagrees with posted delivery quantities.');
        }
    }

    private function verifyEvidence(SalesDelivery $delivery): void
    {
        if ($delivery->cancelled_at !== null || $delivery->cancelled_by !== null || $delivery->cancellation_payload_hash !== null) {
            $this->invalid('Delivery posting evidence conflicts with this request. Reload and review the document.');
        }
        $items = $delivery->items()->with(['salesOrderItem', 'stockMovement.inventoryCostEntry.fifoLayerConsumptions.fifoLayer.stockMovement',
            'stockMovement.inventoryCostEntry.standardCostRevision', 'reservationConsumption'])->orderBy('id')->lockForUpdate()->get();
        $reference = (new SalesDeliveryItem)->getMorphClass();
        $linked = StockMovement::where('reference_type', $reference)->whereIn('reference_id', $items->modelKeys())->orderBy('id')->lockForUpdate()->get();
        if ($linked->count() !== $items->count()) {
            $this->invalid('Delivery posting evidence is incomplete.');
        }
        foreach ($items as $item) {
            $movement = $item->stockMovement;
            $entry = $movement?->inventoryCostEntry;
            $event = $item->reservationConsumption;
            if ($movement === null || $entry === null || $event === null || $movement->type !== StockMovementType::Sale
                || $movement->reference_type !== $reference || (int) $movement->reference_id !== (int) $item->id
                || (int) $movement->variant_id !== (int) $item->salesOrderItem->variant_id
                || (int) $movement->location_id !== (int) $delivery->location_id
                || ! BigDecimal::of($item->quantity)->negated()->isEqualTo($movement->quantity)
                || $movement->idempotency_key !== hash('sha256', 'sales-delivery-item:'.$item->uuid.':post')
                || (int) $movement->created_by !== (int) $delivery->posted_by
                || $entry->entry_type !== 'issue' || ! in_array($entry->method, ['fifo', 'avco', 'standard'], true)
                || $entry->quantity !== $movement->quantity || $entry->unit_cost !== $movement->valuation_unit_cost
                || BigDecimal::of($entry->total_cost)->isPositive() || BigDecimal::of($entry->unit_cost)->isNegative()
                || $entry->purchase_value !== null || $entry->purchase_unit_cost !== null || $entry->purchase_price_variance !== null
                || $event->quantity !== $item->quantity || (int) $event->consumed_by !== (int) $delivery->posted_by
                || ! $event->consumed_at->equalTo($delivery->posted_at)) {
                $this->invalid('Delivery posting evidence is incomplete.');
            }
            $this->inventory->verifyRecordedPayload($movement);
            $parts = $entry->fifoLayerConsumptions;
            if ($entry->method === 'fifo') {
                $quantity = BigDecimal::zero();
                $value = BigDecimal::zero();
                $seenLayers = [];
                foreach ($parts as $part) {
                    $layer = $part->fifoLayer;
                    if (isset($seenLayers[$layer->id]) || (int) $layer->variant_id !== (int) $movement->variant_id
                        || (int) $layer->stockMovement->location_id !== (int) $movement->location_id || $part->unit_cost !== $layer->unit_cost
                        || ! BigDecimal::of($part->quantity)->isPositive() || BigDecimal::of($part->total_cost)->isNegative()) {
                        $this->invalid('FIFO issue consumption evidence is inconsistent.');
                    }
                    $seenLayers[$layer->id] = true;
                    $this->valuation->receiptProvenance($layer);
                    $quantity = $quantity->plus($part->quantity);
                    $value = $value->plus($part->total_cost);
                }
                if (! $quantity->isEqualTo($item->quantity) || ! $value->negated()->isEqualTo($entry->total_cost)) {
                    $this->invalid('FIFO issue consumption evidence is inconsistent.');
                }
            } elseif ($parts->isNotEmpty()) {
                $this->invalid('Non-FIFO issue has unexpected layer consumptions.');
            }
            if ($entry->method === 'standard') {
                $revision = $entry->standardCostRevision;
                if ($revision === null || $revision->new_cost !== $entry->unit_cost || $revision->effective_at->isAfter($delivery->posted_at)) {
                    $this->invalid('Standard Cost issue revision evidence is inconsistent.');
                }
            } elseif ($entry->standard_cost_revision_id !== null) {
                $this->invalid('Non-standard issue has an unexpected standard revision.');
            }
        }
    }

    private function evidenceHash(SalesDelivery $delivery): string
    {
        $items = $delivery->items()->with(['stockMovement.inventoryCostEntry.fifoLayerConsumptions.fifoLayer',
            'stockMovement.inventoryCostEntry.standardCostRevision', 'reservationConsumption'])->orderBy('id')->get();
        $evidence = $items->map(function ($item): array {
            $entry = $item->stockMovement->inventoryCostEntry;

            return [$item->getAttributes(), $item->stockMovement->getAttributes(), $entry->getAttributes(),
                $item->reservationConsumption->getAttributes(), $entry->standardCostRevision?->only(['id', 'standard_cost_id', 'new_cost', 'effective_at']),
                $entry->fifoLayerConsumptions->sortBy('id')->map(fn ($part): array => [$part->getAttributes(),
                    $this->valuation->receiptProvenance($part->fifoLayer)])->values()->all()];
        })->all();

        return hash('sha256', json_encode([$this->fingerprint($delivery), $delivery->getRawOriginal('posted_at'),
            $delivery->posted_by, $delivery->posting_revision, $evidence], JSON_THROW_ON_ERROR));
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['record' => __($message)]);
    }
}
