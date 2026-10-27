<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Services\PaymentFingerprint;
use App\Modules\Payments\Services\PaymentMoney;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Models\VendorPaymentAllocation;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class VendorPaymentAllocationService
{
    private static bool $writing = false;

    public static function isWriting(): bool
    {
        return self::$writing;
    }

    public function __construct(
        private readonly VendorPaymentService $payments,
        private readonly VendorPaymentAccess $access,
        private readonly PaymentMoney $money,
        private readonly PaymentFingerprint $fingerprints,
    ) {}

    public function allocate(User $actor, VendorPayment $payment, VendorBill $bill, mixed $amount, string $operationKey): VendorPaymentAllocation
    {
        return DB::transaction(function () use ($actor, $payment, $bill, $amount, $operationKey): VendorPaymentAllocation {
            $this->payments->lockRegistry();
            $payment = VendorPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->payments->managed($payment);
            $this->access->operation($actor, PurchasingPermissions::PAYMENTS_ALLOCATE, (int) $payment->location_id);
            $this->payments->validateKey($operationKey);
            $amount = $this->money->normalize($amount, $payment->currency);
            $orderId = VendorBill::whereKey($bill->id)->value('purchase_order_id');
            $order = $orderId === null ? null : PurchaseOrder::whereKey($orderId)->lockForUpdate()->first();
            $bill = VendorBill::whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if ($order === null || (int) $order->supplier_id !== (int) $bill->supplier_id
                || (int) $bill->supplier_id !== (int) $payment->supplier_id || (int) $order->location_id !== (int) $payment->location_id
                || ! in_array($order->status, ['confirmed', 'partially_received', 'received', 'closed'], true)) {
                $this->payments->invalid('bill', 'Payment and bill require the same supplier and explicit location ownership on a confirmed order.');
            }
            $hash = $this->fingerprints->make('purchasing', 'vendor_payment_allocate', ['payment_id' => $payment->id, 'bill_id' => $bill->id, 'amount' => $amount, 'actor_id' => $actor->id]);
            $existing = VendorPaymentAllocation::where('operation_key', $operationKey)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->payments->match($existing->payload_fingerprint, $hash);

                return $existing;
            }
            if ($payment->status !== 'posted' || $payment->voided_at !== null || $bill->status !== 'posted') {
                $this->payments->invalid('status', 'Only posted, unreversed payments and posted bills are eligible.');
            }
            $used = BigDecimal::zero();
            foreach ($payment->allocations()->whereNull('released_at')->orderBy('id')->lockForUpdate()->get() as $allocation) {
                $used = $used->plus($allocation->amount);
            }
            $paid = BigDecimal::zero();
            foreach ($bill->paymentAllocations()->whereNull('released_at')->orderBy('id')->lockForUpdate()->get() as $allocation) {
                $source = VendorPayment::whereKey($allocation->vendor_payment_id)->lockForUpdate()->firstOrFail();
                if ($source->status === 'posted' && $source->voided_at === null) {
                    $this->payments->managed($source);
                    if ((int) $source->location_id !== (int) $order->location_id) {
                        $this->payments->invalid('bill', 'Existing allocation ownership requires reconciliation.');
                    }
                    $paid = $paid->plus($allocation->amount);
                }
            }
            if ($used->plus($amount)->isGreaterThan($payment->amount) || $paid->plus($amount)->isGreaterThan($bill->total_amount)) {
                $this->payments->invalid('amount', 'Allocation exceeds the payment remaining amount or bill outstanding.');
            }
            self::$writing = true;
            try {
                $allocation = VendorPaymentAllocation::create(['supplier_id' => $payment->supplier_id, 'vendor_payment_id' => $payment->id,
                    'vendor_bill_id' => $bill->id, 'amount' => $amount, 'operation_key' => $operationKey, 'payload_fingerprint' => $hash,
                    'allocated_by' => $actor->id, 'allocated_at' => now()]);
            } finally {
                self::$writing = false;
            }
            $this->payments->audit($actor, $payment, 'vendor_payment_allocated', ['allocation_id' => $allocation->id, 'vendor_bill_id' => $bill->id, 'amount' => $amount]);

            return $allocation;
        }, 3);
    }

    /** @param list<array{bill_id: int, amount: string|int, operation_key: string}> $allocations */
    public function allocateMany(User $actor, VendorPayment $payment, array $allocations): Collection
    {
        Validator::make(['allocations' => $allocations], ['allocations' => ['required', 'array', 'min:1', 'max:200'],
            'allocations.*' => ['array:bill_id,amount,operation_key'], 'allocations.*.bill_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required'], 'allocations.*.operation_key' => ['required', 'uuid', 'distinct']])->validate();
        usort($allocations, fn (array $first, array $second): int => $first['bill_id'] <=> $second['bill_id']);

        return DB::transaction(function () use ($actor, $payment, $allocations): Collection {
            $this->payments->lockRegistry();
            $result = new Collection;
            foreach ($allocations as $allocation) {
                $result->push($this->allocate($actor, $payment, VendorBill::findOrFail($allocation['bill_id']), $allocation['amount'], $allocation['operation_key']));
            }

            return $result;
        }, 3);
    }

    /** Full tranche correction restores the advance; it never overwrites allocation evidence. */
    public function release(User $actor, VendorPaymentAllocation $allocation, string $operationKey, string $reason): VendorPaymentAllocation
    {
        return DB::transaction(function () use ($actor, $allocation, $operationKey, $reason): VendorPaymentAllocation {
            $this->payments->lockRegistry();
            $paymentId = VendorPaymentAllocation::whereKey($allocation->id)->value('vendor_payment_id');
            $payment = VendorPayment::whereKey($paymentId)->lockForUpdate()->firstOrFail();
            $this->payments->managed($payment);
            $this->access->operation($actor, PurchasingPermissions::PAYMENTS_REVERSE, (int) $payment->location_id);
            $allocation = VendorPaymentAllocation::whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            $this->payments->validateKey($operationKey);
            $reason = trim($reason);
            Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:16000']])->validate();
            $hash = $this->fingerprints->make('purchasing', 'vendor_allocation_release', ['allocation_id' => $allocation->id, 'reason' => $reason, 'actor_id' => $actor->id]);
            $existing = VendorPaymentAllocation::where('release_key', $operationKey)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->payments->match($existing->release_fingerprint, $hash);

                return $existing;
            }
            if ($allocation->released_at !== null || $payment->status !== 'posted' || $payment->voided_at !== null || $allocation->operation_key === null) {
                $this->payments->invalid('allocation', 'Only an active managed tranche on an unreversed posted payment can be released once.');
            }
            self::$writing = true;
            try {
                $allocation->fill(['released_by' => $actor->id, 'released_at' => now(), 'release_reason' => $reason,
                    'release_key' => $operationKey, 'release_fingerprint' => $hash])->save();
            } finally {
                self::$writing = false;
            }
            $this->payments->audit($actor, $payment, 'vendor_payment_allocation_released', ['allocation_id' => $allocation->id, 'reason' => $reason]);

            return $allocation->refresh();
        }, 3);
    }
}
