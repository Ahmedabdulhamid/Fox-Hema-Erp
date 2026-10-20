<?php

namespace App\Modules\Sales\Services;

use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceDeliveryMatch;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesReturnAllocationService
{
    /** Allocation plans are quantities only; no credits, prices, taxes or refunds are computed. */
    public function prepare(SalesOrder $order, array $lines, ?int $invoiceId = null, ?int $excludeReturnId = null): array
    {
        $ids = array_values(array_unique(array_column($lines, 'sales_delivery_item_id')));
        $invoices = $order->invoices()->where('status', 'posted')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($invoiceId !== null && ! $invoices->has($invoiceId)) {
            $this->invalid();
        }
        $matches = SalesInvoiceDeliveryMatch::whereIn('sales_delivery_item_id', $ids)
            ->whereHas('salesInvoiceItem.salesInvoice', fn ($q) => $q->where('status', 'posted'))
            ->with('salesInvoiceItem')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($matches as $match) {
            $invoice = $invoices->get($match->salesInvoiceItem->sales_invoice_id);
            $source = $order->deliveryItems()->find($match->sales_delivery_item_id);
            if ($invoice === null || $source === null || (int) $invoice->location_id !== (int) $order->location_id
                || (int) $match->salesInvoiceItem->sales_order_item_id !== (int) $source->sales_order_item_id
                || (int) $match->salesInvoiceItem->variant_id !== (int) $source->salesOrderItem->variant_id
                || ! BigDecimal::of($match->matched_quantity)->isPositive()) {
                $this->invalid();
            }
            app(SalesInvoiceService::class)->verifyPosted($invoice);
        }
        $existing = DB::table('sales_return_items as i')->join('sales_returns as r', 'r.id', '=', 'i.sales_return_id')
            ->whereIn('i.sales_delivery_item_id', $ids)->whereIn('r.status', ['approved', 'posted'])
            ->when($excludeReturnId !== null, fn ($q) => $q->where('r.id', '!=', $excludeReturnId))
            ->select('i.*', 'r.status as document_status', 'r.posting_payload_hash')->orderBy('i.id')->lockForUpdate()->get();
        $used = [];
        $unbilledUsed = [];
        foreach ($existing as $item) {
            if ($item->unbilled_quantity === null) {
                throw ValidationException::withMessages(['record' => __('returns.legacy_allocation')]);
            }
            $posted = $item->document_status === 'posted';
            if ($posted && $item->posting_payload_hash === null) {
                throw ValidationException::withMessages(['record' => __('returns.legacy_allocation')]);
            }
            $allocated = $posted ? BigDecimal::zero() : BigDecimal::of($item->unbilled_quantity);
            $postedUnbilled = BigDecimal::zero();
            $rows = DB::table($posted ? 'sales_return_posted_allocations' : 'sales_return_invoice_allocations')->where('sales_return_item_id', $item->id)->orderBy('id')->lockForUpdate()->get();
            foreach ($rows as $row) {
                if ($row->sales_invoice_delivery_match_id === null) {
                    $postedUnbilled = $postedUnbilled->plus($row->quantity);
                } else {
                    $used[$row->sales_invoice_delivery_match_id] = ($used[$row->sales_invoice_delivery_match_id] ?? BigDecimal::zero())->plus($row->quantity);
                }
                $allocated = $allocated->plus($row->quantity);
            }
            if (! $allocated->isEqualTo($item->quantity)) {
                $this->invalid();
            }
            $unbilledUsed[$item->sales_delivery_item_id] = ($unbilledUsed[$item->sales_delivery_item_id] ?? BigDecimal::zero())->plus($posted ? $postedUnbilled : $item->unbilled_quantity);
        }
        $unbilled = [];
        foreach ($ids as $id) {
            $delivered = $order->deliveryItems()->findOrFail($id)->quantity;
            $billed = $matches->where('sales_delivery_item_id', $id)->reduce(fn ($sum, $m) => $sum->plus($m->matched_quantity), BigDecimal::zero());
            if ($billed->isGreaterThan($delivered)) {
                $this->invalid();
            }
            $unbilled[$id] = BigDecimal::max(BigDecimal::zero(), BigDecimal::of($delivered)->minus($billed)->minus($unbilledUsed[$id] ?? BigDecimal::zero()));
        }
        foreach ($lines as &$line) {
            $id = $line['sales_delivery_item_id'];
            $remaining = BigDecimal::of($line['quantity']);
            $allocations = [];
            $explicit = array_key_exists('invoice_allocations', $line);
            $selectedItem = $line['sales_invoice_item_id'] ?? null;
            $candidates = $matches->where('sales_delivery_item_id', $id)
                ->filter(fn ($m) => ($invoiceId === null || (int) $m->salesInvoiceItem->sales_invoice_id === $invoiceId)
                    && ($selectedItem === null || (int) $m->sales_invoice_item_id === $selectedItem));
            if ($selectedItem !== null && $candidates->isEmpty()) {
                $this->invalid();
            }
            $requests = $explicit ? $line['invoice_allocations'] : $candidates->map(fn ($m) => [
                'sales_invoice_delivery_match_id' => $m->id, 'quantity' => null])->values()->all();
            $seen = [];
            foreach ($requests as $request) {
                $matchId = (int) $request['sales_invoice_delivery_match_id'];
                $match = $candidates->get($matchId);
                if ($match === null || isset($seen[$matchId])) {
                    $this->invalid();
                }
                $seen[$matchId] = true;
                $available = BigDecimal::of($match->matched_quantity)->minus($used[$matchId] ?? BigDecimal::zero());
                if ($available->isNegative()) {
                    $this->invalid();
                }
                $quantity = $explicit ? BigDecimal::of(app(PurchaseOrderCalculator::class)->decimal($request['quantity'], 'quantity', 18, 4)) : BigDecimal::min($remaining, $available);
                if ($explicit && ! $quantity->isPositive()) {
                    $this->invalid();
                }
                if ($quantity->isGreaterThan($remaining) || $quantity->isGreaterThan($available)) {
                    $this->invalid();
                }
                if ($quantity->isZero()) {
                    continue;
                }
                $allocations[] = ['sales_invoice_delivery_match_id' => $matchId, 'sales_invoice_item_id' => $match->sales_invoice_item_id,
                    'sales_invoice_id' => $match->salesInvoiceItem->sales_invoice_id, 'quantity' => (string) $quantity->toScale(4)];
                $used[$matchId] = ($used[$matchId] ?? BigDecimal::zero())->plus($quantity);
                $remaining = $remaining->minus($quantity);
            }
            if (($selectedItem !== null && ! $remaining->isZero()) || $remaining->isGreaterThan($unbilled[$id])) {
                $this->invalid();
            }
            $unbilled[$id] = $unbilled[$id]->minus($remaining);
            $line['unbilled_quantity'] = (string) $remaining->toScale(4);
            $line['allocations'] = $allocations;
            unset($line['invoice_allocations']);
        }

        return $lines;
    }

    public function verifyDraft(SalesOrder $order, SalesReturn $return): void
    {
        $lines = $return->items()->with('invoiceAllocations')->orderBy('id')->get()->map(fn ($item) => [
            'sales_delivery_item_id' => $item->sales_delivery_item_id, 'quantity' => $item->quantity,
            'sales_invoice_item_id' => $item->sales_invoice_item_id,
            'invoice_allocations' => $item->invoiceAllocations->map(fn ($a) => [
                'sales_invoice_delivery_match_id' => $a->sales_invoice_delivery_match_id, 'quantity' => $a->quantity])->all(),
        ])->all();
        $prepared = $this->prepare($order, $lines, $return->sales_invoice_id, $return->id);
        foreach ($return->items()->orderBy('id')->get() as $index => $item) {
            if ($item->unbilled_quantity !== $prepared[$index]['unbilled_quantity']) {
                $this->invalid();
            }
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['record' => __('returns.allocation_invalid')]);
    }

    /** Approval replay verifies original allocations without reserving newly invoiced quantities. */
    public function verifyRecorded(SalesReturn $return): void
    {
        foreach ($return->items()->with('invoiceAllocations')->orderBy('id')->get() as $item) {
            $sum = BigDecimal::of($item->unbilled_quantity ?? '-1');
            foreach ($item->invoiceAllocations as $allocation) {
                $match = SalesInvoiceDeliveryMatch::find($allocation->sales_invoice_delivery_match_id);
                $invoice = SalesInvoice::find($allocation->sales_invoice_id);
                if ($match === null || $invoice === null || $invoice->status !== 'posted'
                    || (int) $invoice->sales_order_id !== (int) $return->sales_order_id || (int) $invoice->location_id !== (int) $return->location_id
                    || (int) $match->sales_delivery_item_id !== (int) $item->sales_delivery_item_id
                    || (int) $match->sales_invoice_item_id !== (int) $allocation->sales_invoice_item_id
                    || (int) $match->salesInvoiceItem->sales_invoice_id !== (int) $invoice->id
                    || ! BigDecimal::of($allocation->quantity)->isPositive()
                    || BigDecimal::of($allocation->quantity)->isGreaterThan($match->matched_quantity)) {
                    $this->invalid();
                }
                app(SalesInvoiceService::class)->verifyPosted($invoice);
                $sum = $sum->plus($allocation->quantity);
            }
            if (! $sum->isEqualTo($item->quantity)) {
                $this->invalid();
            }
        }
    }
}
