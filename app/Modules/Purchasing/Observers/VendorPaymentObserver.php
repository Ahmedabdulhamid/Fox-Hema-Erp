<?php

namespace App\Modules\Purchasing\Observers;

use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\Purchasing\Models\VendorPayment;
use Illuminate\Validation\ValidationException;

class VendorPaymentObserver
{
    public function creating(VendorPayment $payment): void
    {
        $payment->payment_number = app(DocumentNumberService::class)->allocateFinancial('SP', $payment->uuid);
    }

    public function updating(VendorPayment $payment): void
    {
        if ($payment->isDirty('payment_number')) {
            throw ValidationException::withMessages(['payment_number' => __('Generated codes cannot be changed.')]);
        }
    }
}
