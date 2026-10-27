<?php

namespace App\Modules\Payments\Observers;

use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Modules\Payments\Models\PaymentRefund;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerDocumentObserver
{
    public function creating(Model $model): void
    {
        if ($model instanceof Payment && ! $model->isSettlementReceipt()) {
            return;
        }
        [$prefix,$field] = match (true) {
            $model instanceof Customer => ['CUS', 'code'], $model instanceof Payment => ['RCPT', 'payment_number'],
            $model instanceof PaymentAllocation => ['ALLOC', 'code'], $model instanceof PaymentRefund => ['RFND', 'refund_number'],
        };
        if ($model->getAttribute($field) !== null) {
            throw ValidationException::withMessages([$field => __('Document codes are generated automatically.')]);
        }
        $model->uuid ??= (string) Str::uuid7();
        $model->setAttribute($field, app(DocumentNumberService::class)->allocateFinancial($prefix, $model->uuid));
    }
}
