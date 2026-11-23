<?php

namespace App\Modules\POS\Services;

use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceDeliveryMatch;
use App\Modules\Sales\Services\SalesReturnQuantityService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

class PosOfflineReturnSources
{
    /**
     * @param  Collection<int, SalesInvoice>  $invoices
     * @return array<int, list<array{sales_delivery_item_id: int, sales_invoice_item_id: int, variant_id: int, quantity: string, remaining: string, label: string}>>
     */
    public function forInvoices(Collection $invoices): array
    {
        if ($invoices->isEmpty()) {
            return [];
        }

        $matches = SalesInvoiceDeliveryMatch::query()
            ->whereHas('salesInvoiceItem', fn ($query) => $query->whereIn('sales_invoice_id', $invoices->pluck('id')->all()))
            ->whereHas('salesDeliveryItem.salesDelivery', fn ($query) => $query->where('status', 'posted'))
            ->with([
                'salesInvoiceItem',
                'salesDeliveryItem.salesOrderItem.variant.size',
                'salesDeliveryItem.salesOrderItem.variant.color',
            ])
            ->orderBy('id')
            ->get();
        $quantities = app(SalesReturnQuantityService::class)->quantities(
            $matches->pluck('sales_delivery_item_id')->unique()->values()->all(),
            lock: false,
        );
        $invoiceIds = $invoices->mapWithKeys(fn (SalesInvoice $invoice): array => [$invoice->id => true]);
        $lines = [];

        foreach ($matches as $match) {
            $invoiceId = (int) $match->salesInvoiceItem->sales_invoice_id;
            if (! isset($invoiceIds[$invoiceId])) {
                continue;
            }

            $deliveryItem = $match->salesDeliveryItem;
            $variant = $deliveryItem->salesOrderItem->variant;
            $remaining = $quantities[$deliveryItem->id]['remaining'] ?? '0.0000';
            $lines[$invoiceId][] = [
                'sales_delivery_item_id' => (int) $deliveryItem->id,
                'sales_invoice_item_id' => (int) $match->sales_invoice_item_id,
                'variant_id' => (int) $variant->id,
                'quantity' => (string) BigDecimal::of($match->matched_quantity)->toScale(4),
                'remaining' => (string) BigDecimal::min($remaining, $match->matched_quantity)->toScale(4),
                'label' => $variant->displayName(),
            ];
        }

        return $lines;
    }
}
