<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Purchasing\Models\VendorPayment;
use Illuminate\Support\Facades\DB;

class PurchaseSettlement
{
    public function __construct(private readonly VendorPaymentService $payments, private readonly VendorPaymentAllocationService $allocations) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{bill_id: int, amount: string|int, operation_key: string}>  $allocations
     */
    public function pay(User $actor, array $data, string $operationKey, array $allocations): VendorPayment
    {
        return DB::transaction(function () use ($actor, $data, $operationKey, $allocations): VendorPayment {
            $this->payments->lockRegistry();
            $payment = $this->payments->post($actor, $this->payments->createDraft($actor, $data, $operationKey));
            $this->allocations->allocateMany($actor, $payment, $allocations);

            return $payment;
        }, 3);
    }
}
