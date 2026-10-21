<?php

namespace App\Modules\Sales\Filament;

use Illuminate\Validation\ValidationException;

trait ReportsSalesValidationErrors
{
    protected function onValidationError(ValidationException $exception): void
    {
        SalesMutation::notify($exception);
    }
}
