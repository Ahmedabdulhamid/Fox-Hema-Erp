<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorPayment;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class SupplierFinancialBalances
{
    public function __construct(private readonly VendorPaymentAccess $access, private readonly VendorPaymentService $payments) {}

    /** @return array{paid: string, outstanding: string} */
    public function bill(User $actor, VendorBill $bill): array
    {
        return DB::transaction(function () use ($actor, $bill): array {
            $this->payments->lockRegistry();
            $bill = VendorBill::with('purchaseOrder')->findOrFail($bill->id);
            $order = $bill->purchaseOrder;
            if ($order === null || (int) $order->supplier_id !== (int) $bill->supplier_id) {
                $this->payments->invalid('bill', 'Bill ownership requires explicit reconciliation.');
            }
            $this->access->authorize($actor, PurchasingPermissions::PAYMENTS_VIEW, (int) $order->location_id);

            return $this->billAmounts($bill);
        }, 3);
    }

    /** @return array{allocated: string, unallocated: string} */
    public function payment(User $actor, VendorPayment $payment): array
    {
        return DB::transaction(function () use ($actor, $payment): array {
            $this->payments->lockRegistry();
            $payment = VendorPayment::findOrFail($payment->id);
            $this->payments->managed($payment);
            $this->access->authorize($actor, PurchasingPermissions::PAYMENTS_VIEW, (int) $payment->location_id);

            return $this->paymentAmounts($payment);
        }, 3);
    }

    /**
     * Read-only rendering snapshot. Submissions must use payment() and the allocation services.
     *
     * @return array{allocated: string, unallocated: string}
     */
    public function paymentForDisplay(User $actor, VendorPayment $payment): array
    {
        $payment = VendorPayment::findOrFail($payment->id);
        $this->payments->managed($payment);
        $this->access->authorize($actor, PurchasingPermissions::PAYMENTS_VIEW, (int) $payment->location_id);

        return $this->paymentAmounts($payment);
    }

    /**
     * Scope follows document ownership. Unknown legacy ownership fails closed rather than implying a complete balance.
     *
     * @return array{gross_open_invoices: string, unallocated_advances: string, net_supplier_balance: string}
     */
    public function supplier(User $actor, int $supplierId): array
    {
        return DB::transaction(function () use ($actor, $supplierId): array {
            $this->payments->lockRegistry();
            $this->access->authorize($actor, PurchasingPermissions::PAYMENTS_VIEW);
            if (VendorPayment::where('supplier_id', $supplierId)->where('status', 'posted')->whereNull('voided_at')
                ->where(fn ($query) => $query->whereNull('location_id')->orWhereNull('currency')->orWhereNull('payment_method_id')->orWhereNull('idempotency_key')->orWhereNull('payload_fingerprint'))->exists()
                || VendorBill::where('supplier_id', $supplierId)->where('status', 'posted')->whereDoesntHave('purchaseOrder', fn ($query) => $query->where('supplier_id', $supplierId))->exists()) {
                $this->payments->invalid('supplier', 'Unreconciled legacy financial ownership prevents a reliable supplier balance.');
            }
            $open = BigDecimal::zero();
            $advances = BigDecimal::zero();
            $bills = VendorBill::where('supplier_id', $supplierId)->where('status', 'posted')->whereHas('purchaseOrder', fn ($query) => $this->access->scope($query, $actor));
            foreach ($bills->with('purchaseOrder')->get() as $bill) {
                $open = $open->plus($this->billAmounts($bill)['outstanding']);
            }
            foreach ($this->access->scope(VendorPayment::query(), $actor)->where('supplier_id', $supplierId)->where('status', 'posted')->whereNull('voided_at')->get() as $payment) {
                $this->payments->managed($payment);
                $advances = $advances->plus($this->paymentAmounts($payment)['unallocated']);
            }

            return ['gross_open_invoices' => $this->decimal($open), 'unallocated_advances' => $this->decimal($advances), 'net_supplier_balance' => $this->decimal($open->minus($advances))];
        }, 3);
    }

    /** @return array{paid: string, outstanding: string} */
    private function billAmounts(VendorBill $bill): array
    {
        $paid = BigDecimal::zero();
        if ($bill->status !== 'posted') {
            return ['paid' => '0.0000', 'outstanding' => '0.0000'];
        }
        foreach ($bill->paymentAllocations()->whereNull('released_at')->with('vendorPayment')->get() as $allocation) {
            $payment = $allocation->vendorPayment;
            if ($payment->status === 'posted' && $payment->voided_at === null) {
                $this->payments->managed($payment);
                if ((int) $payment->location_id !== (int) $bill->purchaseOrder->location_id || (int) $payment->supplier_id !== (int) $bill->supplier_id) {
                    $this->payments->invalid('allocation', 'Allocation ownership requires reconciliation.');
                }
                $paid = $paid->plus($allocation->amount);
            }
        }
        $outstanding = BigDecimal::of($bill->total_amount)->minus($paid);
        if ($outstanding->isNegative()) {
            $this->payments->invalid('bill', 'Existing allocations exceed the bill total.');
        }

        return ['paid' => $this->decimal($paid), 'outstanding' => $this->decimal($outstanding)];
    }

    /** @return array{allocated: string, unallocated: string} */
    private function paymentAmounts(VendorPayment $payment): array
    {
        if ($payment->status !== 'posted' || $payment->voided_at !== null) {
            return ['allocated' => '0.0000', 'unallocated' => '0.0000'];
        }
        $allocated = BigDecimal::zero();
        foreach ($payment->allocations()->whereNull('released_at')->with('vendorBill.purchaseOrder')->get() as $allocation) {
            $bill = $allocation->vendorBill;
            if ($bill->status !== 'posted' || $bill->purchaseOrder === null
                || (int) $bill->purchaseOrder->location_id !== (int) $payment->location_id
                || (int) $bill->supplier_id !== (int) $payment->supplier_id) {
                $this->payments->invalid('allocation', 'Existing allocation evidence requires reconciliation.');
            }
            $allocated = $allocated->plus($allocation->amount);
        }
        $remaining = BigDecimal::of($payment->amount)->minus($allocated);
        if ($remaining->isNegative()) {
            $this->payments->invalid('payment', 'Existing allocations exceed the payment amount.');
        }

        return ['allocated' => $this->decimal($allocated), 'unallocated' => $this->decimal($remaining)];
    }

    private function decimal(BigDecimal $amount): string
    {
        return (string) $amount->toScale(4);
    }
}
