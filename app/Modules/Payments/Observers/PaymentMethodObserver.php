<?php

namespace App\Modules\Payments\Observers;

use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\Payments\Models\PaymentMethod;
use Illuminate\Validation\ValidationException;

class PaymentMethodObserver
{
    public function creating(PaymentMethod $method): void
    {
        $method->code = app(DocumentNumberService::class)->allocateFinancial('PM', $method->uuid);
    }

    public function updating(PaymentMethod $method): void
    {
        if ($method->isDirty(['code', 'type', 'uuid'])) {
            throw ValidationException::withMessages(['code' => __('payment_methods.validation.immutable')]);
        }
    }
}
