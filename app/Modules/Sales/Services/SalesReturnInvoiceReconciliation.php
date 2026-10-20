<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\SalesInvoiceDeliveryMatch;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesReturnInvoiceReconciliation
{
    public function post(SalesOrder $order, SalesReturn $return): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Return invoice reconciliation requires the document transaction.');
        }
        $items = $return->items()->with('invoiceAllocations')->orderBy('id')->get();
        $ids = $items->pluck('sales_delivery_item_id')->unique()->all();
        $matches = SalesInvoiceDeliveryMatch::whereIn('sales_delivery_item_id', $ids)
            ->whereHas('salesInvoiceItem.salesInvoice', fn ($q) => $q->where('status', 'posted'))
            ->with('salesInvoiceItem.salesInvoice')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($matches as $match) {
            $invoice = $match->salesInvoiceItem->salesInvoice;
            $source = $order->deliveryItems()->findOrFail($match->sales_delivery_item_id);
            if ((int) $invoice->sales_order_id !== (int) $order->id || (int) $invoice->location_id !== (int) $order->location_id
                || (int) $match->salesInvoiceItem->sales_order_item_id !== (int) $source->sales_order_item_id
                || (int) $match->salesInvoiceItem->variant_id !== (int) $source->salesOrderItem->variant_id) {
                $this->invalid();
            }
            app(SalesInvoiceService::class)->verifyPosted($invoice);
        }
        $used = [];
        $usedUnbilled = [];
        $others = DB::table('sales_return_items as i')->join('sales_returns as r', 'r.id', '=', 'i.sales_return_id')
            ->whereIn('i.sales_delivery_item_id', $ids)->where('r.id', '!=', $return->id)->whereIn('r.status', ['approved', 'posted'])
            ->select('i.*', 'r.status as document_status', 'r.posting_payload_hash')->orderBy('i.id')->lockForUpdate()->get();
        foreach ($others as $other) {
            $posted = $other->document_status === 'posted';
            if ($posted && $other->posting_payload_hash === null) {
                $this->invalid();
            }
            if ($posted) {
                app(SalesReturnPostingService::class)->verifyFingerprint(SalesReturn::findOrFail($other->sales_return_id));
            }
            $rows = DB::table($posted ? 'sales_return_posted_allocations' : 'sales_return_invoice_allocations')
                ->where('sales_return_item_id', $other->id)->orderBy('id')->lockForUpdate()->get();
            $sum = $posted ? BigDecimal::zero() : BigDecimal::of($other->unbilled_quantity ?? '-1');
            foreach ($rows as $row) {
                $sum = $sum->plus($row->quantity);
                if ($row->sales_invoice_delivery_match_id === null) {
                    $usedUnbilled[$other->sales_delivery_item_id] = ($usedUnbilled[$other->sales_delivery_item_id] ?? BigDecimal::zero())->plus($row->quantity);
                } else {
                    $used[$row->sales_invoice_delivery_match_id] = ($used[$row->sales_invoice_delivery_match_id] ?? BigDecimal::zero())->plus($row->quantity);
                }
            }
            if (! $sum->isEqualTo($other->quantity)) {
                $this->invalid();
            }
        }
        $unbilled = [];
        foreach ($ids as $id) {
            $billed = $matches->where('sales_delivery_item_id', $id)->reduce(fn ($s, $m) => $s->plus($m->matched_quantity), BigDecimal::zero());
            $unbilled[$id] = BigDecimal::of($order->deliveryItems()->findOrFail($id)->quantity)->minus($billed)->minus($usedUnbilled[$id] ?? BigDecimal::zero());
            if ($unbilled[$id]->isNegative()) {
                $this->invalid();
            }
        }
        // Retain every originally approved billed allocation before distributing newly billed portions.
        $plans = [];
        foreach ($items as $item) {
            $plans[$item->id] = [];
            foreach ($item->invoiceAllocations as $allocation) {
                $match = $matches->get($allocation->sales_invoice_delivery_match_id);
                if ($match === null || (int) $match->sales_delivery_item_id !== (int) $item->sales_delivery_item_id) {
                    $this->invalid();
                }
                $this->reserve($used, $match, BigDecimal::of($allocation->quantity));
                $plans[$item->id][$match->id] = BigDecimal::of($allocation->quantity);
            }
        }
        foreach ($items as $item) {
            $remaining = BigDecimal::of($item->quantity);
            foreach ($plans[$item->id] as $quantity) {
                $remaining = $remaining->minus($quantity);
            }
            foreach ($matches->where('sales_delivery_item_id', $item->sales_delivery_item_id) as $match) {
                $available = BigDecimal::of($match->matched_quantity)->minus($used[$match->id] ?? BigDecimal::zero());
                if ($available->isNegative()) {
                    $this->invalid();
                }
                $quantity = BigDecimal::min($remaining, $available);
                if ($quantity->isPositive()) {
                    $this->reserve($used, $match, $quantity);
                    $plans[$item->id][$match->id] = ($plans[$item->id][$match->id] ?? BigDecimal::zero())->plus($quantity);
                    $remaining = $remaining->minus($quantity);
                }
            }
            if ($remaining->isNegative() || $remaining->isGreaterThan($unbilled[$item->sales_delivery_item_id])) {
                $this->invalid();
            }
            $unbilled[$item->sales_delivery_item_id] = $unbilled[$item->sales_delivery_item_id]->minus($remaining);
            foreach ($plans[$item->id] as $matchId => $quantity) {
                $match = $matches[$matchId];
                $this->insert($return, $item, $quantity, $match);
            }
            if ($remaining->isPositive()) {
                $this->insert($return, $item, $remaining, null);
            }
        }
    }

    public function unbilled(int $deliveryItemId): string
    {
        $posted = DB::table('sales_return_items as i')->join('sales_returns as r', 'r.id', '=', 'i.sales_return_id')
            ->where('i.sales_delivery_item_id', $deliveryItemId)->where('r.status', 'posted')->select('i.*', 'r.posting_payload_hash')->get();
        $unbilled = BigDecimal::zero();
        foreach ($posted as $item) {
            app(SalesReturnPostingService::class)->verifyFingerprint(SalesReturn::findOrFail($item->sales_return_id));
            $rows = DB::table('sales_return_posted_allocations')->where('sales_return_item_id', $item->id)->get();
            $total = BigDecimal::zero();
            foreach ($rows as $row) {
                $total = $total->plus($row->quantity);
                if ($row->sales_invoice_delivery_match_id === null) {
                    $unbilled = $unbilled->plus($row->quantity);
                }
            }
            if ($item->posting_payload_hash === null || ! $total->isEqualTo($item->quantity)) {
                $this->invalid();
            }
        }

        return (string) $unbilled->toScale(4);
    }

    private function reserve(array &$used, SalesInvoiceDeliveryMatch $match, BigDecimal $quantity): void
    {
        $next = ($used[$match->id] ?? BigDecimal::zero())->plus($quantity);
        if (! $quantity->isPositive() || $next->isGreaterThan($match->matched_quantity)) {
            $this->invalid();
        }
        $used[$match->id] = $next;
    }

    private function insert(SalesReturn $return, SalesReturnItem $item, BigDecimal $quantity, ?SalesInvoiceDeliveryMatch $match): void
    {
        DB::table('sales_return_posted_allocations')->insert([
            'sales_return_id' => $return->id, 'sales_return_item_id' => $item->id, 'sales_delivery_item_id' => $item->sales_delivery_item_id,
            'sales_invoice_delivery_match_id' => $match?->id, 'sales_invoice_item_id' => $match?->sales_invoice_item_id,
            'sales_invoice_id' => $match?->salesInvoiceItem->sales_invoice_id, 'allocation_key' => $match === null ? 'unbilled' : 'match:'.$match->id,
            'quantity' => (string) $quantity->toScale(4), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['record' => __('returns.reconciliation_invalid')]);
    }
}
