<?php

namespace App\Modules\Purchasing\Filament;

use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

trait ReportsBillValidationErrors
{
    protected function onValidationError(ValidationException $exception): void
    {
        Notification::make()->title(__('Cannot save or post bill'))->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
    }
}
