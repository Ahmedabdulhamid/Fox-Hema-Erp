<?php

namespace App\Modules\Sales\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\QuarantineReceipt;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\InventoryCosting\Services\SalesReturnValuationService;
use App\Modules\InventoryCosting\Services\StockIssueValuationService;
use App\Modules\Sales\Models\SalesInvoiceDeliveryMatch;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnCostAllocation;
use App\Modules\Sales\Models\SalesReturnItem;
use App\Modules\Sales\Models\SalesReturnScrapReceipt;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class SalesReturnPostingService
{
    public function __construct(private readonly SalesReturnService $returns, private readonly SalesReturnApprovalService $approval,
        private readonly SalesReturnQuantityService $quantities, private readonly SalesReturnValuationService $valuation,
        private readonly SalesReturnInvoiceReconciliation $invoices) {}

    public function post(User $actor, SalesReturn $return, string $revision): SalesReturn
    {
        return DB::transaction(function () use ($actor, $return, $revision): SalesReturn {
            [$order, $return] = $this->returns->lock($actor, $return, SalesPermissions::RETURNS_POST);
            app(InventoryAccess::class)->authorizePosting($actor, (int) $return->location_id);
            abort_unless(app(ModuleAccess::class)->isActive('inventory_costing'), 403);
            $this->returns->validateSources($order, $return);
            if ($return->status === 'posted') {
                if (! in_array($revision, [$return->posting_revision, $this->returns->revision($return)], true)) {
                    $this->returns->invalid('changed');
                }
                $this->verifyPosted($return);

                return $return->load('costAllocations.stockMovement.inventoryCostEntry', 'postedAllocations', 'quantityClaims');
            }
            $this->returns->checkRevision($return, $revision);
            $this->returns->assertNoDownstream($return);
            $this->approval->verifyApproved($return);
            if ($return->posting_payload_hash !== null || $return->posting_evidence_hash !== null || $return->posting_revision !== null
                || $return->costAllocations()->exists() || $return->postedAllocations()->exists()) {
                $this->returns->invalid('posting_evidence_invalid');
            }
            $items = $return->items()->orderBy('id')->get();
            $this->quantities->quantities($items->pluck('sales_delivery_item_id')->unique()->all());
            $postedAt = now();
            $this->invoices->post($order, $return);
            $this->valuation->post($return, $actor, $postedAt);
            $consumed = DB::table('sales_return_quantity_claims')->where('sales_return_id', $return->id)->where('status', 'active')
                ->update(['status' => 'consumed', 'updated_at' => $postedAt]);
            if ($consumed !== $items->count()) {
                $this->returns->invalid('quantity_evidence_invalid');
            }
            DB::table('sales_returns')->where('id', $return->id)->update([
                'status' => 'posted', 'posted_by' => $actor->id, 'posted_at' => $postedAt,
                'posting_revision' => $revision, 'updated_at' => $postedAt,
            ]);
            $return->refresh();
            DB::table('sales_returns')->where('id', $return->id)->update(['posting_payload_hash' => $this->payloadHash($return)]);
            $return->refresh();
            DB::table('sales_returns')->where('id', $return->id)->update(['posting_evidence_hash' => $this->evidenceHash($return)]);
            $this->verifyPosted($return->refresh());
            $this->returns->audit($actor, $return, 'sales_return_posted');

            return $return->load('costAllocations.stockMovement.inventoryCostEntry', 'postedAllocations', 'quantityClaims');
        }, 3);
    }

    public function verifyPosted(SalesReturn $return): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Return evidence verification requires the document transaction.');
        }
        $this->verifyFingerprint($return);
        $this->approval->verifyApproved($return, posted: true);
        $items = $return->items()->with('salesDeliveryItem.stockMovement.inventoryCostEntry')->orderBy('id')->get();
        $allocations = $return->costAllocations()->with('stockMovement.inventoryCostEntry')->orderBy('id')->lockForUpdate()->get();
        $expectedMovements = 0;
        foreach ($items as $item) {
            $parts = $allocations->where('sales_return_item_id', $item->id);
            $quantity = $parts->reduce(fn ($sum, $part) => $sum->plus($part->quantity), BigDecimal::zero());
            if (! $quantity->isEqualTo($item->quantity) || $parts->isEmpty()) {
                $this->returns->invalid('posting_evidence_invalid');
            }
            $original = $item->salesDeliveryItem->stockMovement->inventoryCostEntry;
            $this->valuation->verifyBudget($original);
            foreach ($parts as $part) {
                if ((int) $part->original_cost_entry_id !== (int) $original->id || $part->method !== $original->method
                    || $part->disposition !== $item->disposition || (int) $part->variant_id !== (int) $item->variant_id
                    || (int) $part->location_id !== (int) $return->location_id || (int) $part->posted_by !== (int) $return->posted_by
                    || ! $part->posted_at->equalTo($return->posted_at)
                    || $part->operation_identity !== hash('sha256', 'sales-return-item:'.$item->uuid.':'.$part->slice_key)
                    || $part->original_standard_revision_id !== $original->standard_cost_revision_id) {
                    $this->returns->invalid('posting_evidence_invalid');
                }
                $quarantine = QuarantineReceipt::where('return_cost_allocation_id', $part->id)->first();
                $scrap = SalesReturnScrapReceipt::where('return_cost_allocation_id', $part->id)->first();
                if ($part->disposition === 'restock') {
                    $expectedMovements++;
                    $movement = $part->stockMovement;
                    $entry = $movement?->inventoryCostEntry;
                    if ($movement === null || $entry === null || $quarantine !== null || $scrap !== null
                        || $movement->type !== StockMovementType::Return || $movement->quantity !== $part->quantity
                        || (int) $movement->variant_id !== (int) $part->variant_id || (int) $movement->location_id !== (int) $part->location_id
                        || $movement->reference_type !== $part->getMorphClass() || (int) $movement->reference_id !== (int) $part->id
                        || (int) $movement->created_by !== (int) $return->posted_by || $entry->entry_type !== 'reversal' || $entry->method !== $part->method
                        || $entry->quantity !== $part->quantity || $entry->total_cost !== $part->carrying_value
                        || $entry->unit_cost !== $movement->valuation_unit_cost
                        || $movement->idempotency_key !== hash('sha256', 'sales-return-restock:'.$part->operation_identity)) {
                        $this->returns->invalid('posting_evidence_invalid');
                    }
                    app(InventoryPostingService::class)->verifyRecordedPayload($movement);
                    if ($part->method === 'standard') {
                        $current = $entry->standardCostRevision;
                        if ($current === null || (int) $current->id !== (int) $part->current_standard_revision_id
                            || $current->new_cost !== $entry->unit_cost || $current->effective_at->isAfter($return->posted_at)
                            || (int) $current->standardCost->variant_id !== (int) $part->variant_id) {
                            $this->returns->invalid('posting_evidence_invalid');
                        }
                    } elseif ($entry->unit_cost !== $part->original_unit_cost || $part->current_standard_revision_id !== null) {
                        $this->returns->invalid('posting_evidence_invalid');
                    }
                    $layer = $movement->fifoLayer;
                    if ($part->method === 'fifo') {
                        if ($layer === null || (int) $layer->return_cost_allocation_id !== (int) $part->id) {
                            $this->returns->invalid('posting_evidence_invalid');
                        }
                        app(StockIssueValuationService::class)->receiptProvenance($layer);
                    } elseif ($layer !== null) {
                        $this->returns->invalid('posting_evidence_invalid');
                    }
                } elseif ($part->disposition === 'damaged') {
                    if ($part->stock_movement_id !== null || $scrap !== null || $quarantine === null
                        || $quarantine->quantity !== $part->quantity || $quarantine->original_cost !== $part->original_cost
                        || $quarantine->provisional_carrying_value !== $part->original_cost
                        || (int) $quarantine->location_id !== (int) $part->location_id || (int) $quarantine->variant_id !== (int) $part->variant_id
                        || (int) $quarantine->received_by !== (int) $return->posted_by || ! $quarantine->received_at->equalTo($return->posted_at)
                        || $quarantine->inspection_status !== 'pending_inspection'
                        || $quarantine->valuation_policy !== 'original-cost-provisional-pending-inspection') {
                        $this->returns->invalid('posting_evidence_invalid');
                    }
                } elseif ($part->stock_movement_id !== null || $quarantine !== null || $scrap === null
                    || $scrap->quantity !== $part->quantity || $scrap->original_cost !== $part->original_cost || $scrap->operational_loss_value !== $part->original_cost
                    || $scrap->disposal_status !== 'pending_disposal' || $scrap->accounting_status !== 'not_recorded'
                    || (int) $scrap->accepted_by !== (int) $return->posted_by || trim($scrap->reason) === '') {
                    $this->returns->invalid('posting_evidence_invalid');
                }
            }
            $this->verifyInvoiceAllocations($return, $item);
        }
        if (StockMovement::where('reference_type', (new SalesReturnCostAllocation)->getMorphClass())
            ->whereIn('reference_id', $allocations->modelKeys())->count() !== $expectedMovements) {
            $this->returns->invalid('posting_evidence_invalid');
        }
        $this->quantities->quantities($items->pluck('sales_delivery_item_id')->unique()->all());
        $restockIds = $allocations->where('disposition', 'restock')->pluck('variant_id')->unique()->values()->all();
        if ($restockIds !== []) {
            app(StockIssueValuationService::class)->verifyCurrent($restockIds, (int) $return->location_id);
        }
    }

    /** Read-only historical integrity check for invoice eligibility and subsequent return cost budgets. */
    public function verifyFingerprint(SalesReturn $return): void
    {
        if ($return->status !== 'posted' || $return->posted_at === null || $return->posted_by === null
            || $return->posting_revision === null || $return->posting_payload_hash === null || $return->posting_evidence_hash === null
            || ! hash_equals($return->posting_payload_hash, $this->payloadHash($return))
            || ! hash_equals($return->posting_evidence_hash, $this->evidenceHash($return))) {
            $this->returns->invalid('posting_evidence_invalid');
        }
    }

    private function verifyInvoiceAllocations(SalesReturn $return, SalesReturnItem $item): void
    {
        $rows = $item->postedAllocations()->orderBy('id')->get();
        $quantity = BigDecimal::zero();
        foreach ($rows as $row) {
            $quantity = $quantity->plus($row->quantity);
            if ($row->sales_invoice_delivery_match_id === null) {
                continue;
            }
            $match = SalesInvoiceDeliveryMatch::find($row->sales_invoice_delivery_match_id);
            $invoice = $row->salesInvoice;
            if ($match === null || $invoice === null || $invoice->status !== 'posted'
                || (int) $match->sales_delivery_item_id !== (int) $item->sales_delivery_item_id
                || (int) $match->sales_invoice_item_id !== (int) $row->sales_invoice_item_id
                || (int) $invoice->sales_order_id !== (int) $return->sales_order_id || (int) $invoice->location_id !== (int) $return->location_id) {
                $this->returns->invalid('reconciliation_invalid');
            }
            app(SalesInvoiceService::class)->verifyPosted($invoice);
            $used = DB::table('sales_return_posted_allocations as a')->join('sales_returns as r', 'r.id', '=', 'a.sales_return_id')
                ->where('r.status', 'posted')->where('a.sales_invoice_delivery_match_id', $match->id)->sum('a.quantity');
            if (BigDecimal::of($used)->isGreaterThan($match->matched_quantity)) {
                $this->returns->invalid('reconciliation_invalid');
            }
        }
        if (! $quantity->isEqualTo($item->quantity)) {
            $this->returns->invalid('reconciliation_invalid');
        }
        $billed = DB::table('sales_invoice_delivery_matches as m')->join('sales_invoice_items as i', 'i.id', '=', 'm.sales_invoice_item_id')
            ->join('sales_invoices as inv', 'inv.id', '=', 'i.sales_invoice_id')->where('inv.status', 'posted')
            ->where('m.sales_delivery_item_id', $item->sales_delivery_item_id)->sum('m.matched_quantity');
        if (BigDecimal::of($billed)->plus($this->invoices->unbilled((int) $item->sales_delivery_item_id))->isGreaterThan($item->salesDeliveryItem->quantity)) {
            $this->returns->invalid('reconciliation_invalid');
        }
    }

    private function payloadHash(SalesReturn $return): string
    {
        return hash('sha256', json_encode([$this->returns->fingerprint($return), $return->approval_payload_hash,
            $return->approval_evidence_hash, $return->posting_revision, $return->posted_by, $return->getRawOriginal('posted_at')], JSON_THROW_ON_ERROR));
    }

    private function evidenceHash(SalesReturn $return): string
    {
        $parts = $return->costAllocations()->with('stockMovement.inventoryCostEntry', 'stockMovement.fifoLayer')->orderBy('id')->get();
        $ids = $parts->modelKeys();
        $evidence = $parts->map(fn ($p) => [$p->getAttributes(), $p->stockMovement?->getAttributes(),
            $p->stockMovement?->inventoryCostEntry?->getAttributes(),
            $p->stockMovement?->fifoLayer?->only(['id', 'uuid', 'variant_id', 'stock_movement_id', 'quantity', 'unit_cost',
                'received_at', 'source_layer_id', 'initial_value', 'available_at', 'return_cost_allocation_id'])])->all();

        return hash('sha256', json_encode([$this->payloadHash($return), $evidence,
            $return->quantityClaims()->orderBy('id')->get()->map->getAttributes()->all(),
            $return->postedAllocations()->orderBy('id')->get()->map->getAttributes()->all(),
            QuarantineReceipt::whereIn('return_cost_allocation_id', $ids)->orderBy('id')->get()->map->getAttributes()->all(),
            SalesReturnScrapReceipt::whereIn('return_cost_allocation_id', $ids)->orderBy('id')->get()->map->getAttributes()->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
