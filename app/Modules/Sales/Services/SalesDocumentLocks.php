<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

class SalesDocumentLocks
{
    /** Registry and order locks precede this shared document order. Stock locks follow all Sales evidence locks. */
    public function acquire(SalesOrder $order): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Sales document locks require a transaction.');
        }
        $order->items()->orderBy('id')->lockForUpdate()->get();
        $order->deliveries()->orderBy('id')->lockForUpdate()->get();
        $order->deliveryItems()->orderBy('id')->lockForUpdate()->get();
        $invoices = $order->invoices()->orderBy('id')->lockForUpdate()->get()->modelKeys();
        DB::table('sales_invoice_items')->whereIn('sales_invoice_id', $invoices)->orderBy('id')->lockForUpdate()->get();
        $returns = $order->returns()->orderBy('id')->lockForUpdate()->get()->modelKeys();
        DB::table('sales_return_items')->whereIn('sales_return_id', $returns)->orderBy('id')->lockForUpdate()->get();
        DB::table('sales_return_quantity_claims')->whereIn('sales_return_id', $returns)->orderBy('id')->lockForUpdate()->get();
        DB::table('sales_invoice_delivery_matches')->whereIn('sales_delivery_item_id', $order->deliveryItems()->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        DB::table('sales_return_invoice_allocations')->whereIn('sales_return_id', $returns)->orderBy('id')->lockForUpdate()->get();
        DB::table('sales_return_posted_allocations')->whereIn('sales_return_id', $returns)->orderBy('id')->lockForUpdate()->get();
    }
}
