<?php

namespace App\Modules\Reporting\Observers;

use App\Modules\Reporting\Services\PurchasingProjection;
use App\Modules\Reporting\Services\PurchasingProjectionRecovery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Dirty revisions and cache epochs share the source transaction; jobs run after commit. */
class PurchasingReportingObserver
{
    public function saved(Model $model): void
    {
        $this->changed($model);
    }

    public function deleted(Model $model): void
    {
        $this->changed($model);
    }

    private function changed(Model $model): void
    {
        if ($model->getConnection() !== DB::connection() || ! Schema::hasTable('reporting_purchasing_partitions')) {
            return;
        }
        $orders = [];
        $payments = [];
        foreach ([$model->getAttributes(), $model->getRawOriginal()] as $attributes) {
            $order = $attributes['purchase_order_id'] ?? null;
            $payment = $attributes['vendor_payment_id'] ?? null;
            $bill = $attributes['vendor_bill_id'] ?? null;
            if ($model->getTable() === 'purchase_orders') {
                $order = $model->getKey();
                $this->request($attributes['location_id'] ?? null, $attributes['order_date'] ?? null);
            }
            if ($model->getTable() === 'vendor_payments') {
                $payment = $model->getKey();
                $this->request($attributes['location_id'] ?? null, $attributes['payment_date'] ?? null);
                $this->request($attributes['location_id'] ?? null, $attributes['voided_at'] ?? null);
            }
            if ($model->getTable() === 'vendor_bills') {
                $bill = $model->getKey();
            }
            if (in_array($model->getTable(), ['purchase_receipts', 'vendor_bills'], true) && $order !== null) {
                $location = DB::table('purchase_orders')->where('id', $order)->value('location_id');
                $this->request($location, $attributes['posted_at'] ?? $attributes['bill_date'] ?? null);
            }
            if ($bill !== null) {
                $record = DB::table('vendor_bills')->where('id', $bill)->first(['purchase_order_id']);
                $order = $record?->purchase_order_id ?? $order;
            }
            if ($order !== null) {
                $orders[] = $order;
            }
            if ($payment !== null) {
                $payments[] = $payment;
            }
        }
        foreach (array_unique($orders) as $orderId) {
            $order = DB::table('purchase_orders')->where('id', $orderId)->first(['location_id', 'order_date']);
            if ($order === null) {
                continue;
            }
            $this->request($order->location_id, $order->order_date);
            foreach (DB::table('purchase_receipts')->where('purchase_order_id', $orderId)->selectRaw('DATE(COALESCE(posted_at,?)) AS day', [$order->order_date])->distinct()->pluck('day') as $date) {
                $this->request($order->location_id, $date);
            }
            foreach (DB::table('vendor_bills')->where('purchase_order_id', $orderId)->selectRaw('DATE(COALESCE(posted_at,bill_date)) AS day')->distinct()->pluck('day') as $date) {
                $this->request($order->location_id, $date);
            }
        }
        foreach (array_unique($payments) as $paymentId) {
            $payment = DB::table('vendor_payments')->where('id', $paymentId)->first(['location_id', 'payment_date', 'voided_at']);
            if ($payment === null) {
                continue;
            }
            $this->request($payment->location_id, $payment->payment_date);
            $this->request($payment->location_id, $payment->voided_at);
            foreach (DB::table('vendor_payment_allocations as a')->join('vendor_bills as b', 'b.id', '=', 'a.vendor_bill_id')
                ->join('purchase_orders as o', 'o.id', '=', 'b.purchase_order_id')->where('a.vendor_payment_id', $paymentId)
                ->selectRaw('o.location_id,DATE(COALESCE(b.posted_at,b.bill_date)) AS day')->distinct()->get() as $bill) {
                $this->request($bill->location_id, $bill->day);
            }
        }
    }

    private function request(mixed $locationId, mixed $date): void
    {
        if ($locationId === null || $date === null) {
            return;
        }
        $date = substr((string) $date, 0, 10);
        app(PurchasingProjection::class)->request((int) $locationId, $date);
        DB::afterCommit(fn () => app(PurchasingProjectionRecovery::class)->dispatch(1));
    }
}
