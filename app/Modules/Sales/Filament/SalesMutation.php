<?php

namespace App\Modules\Sales\Filament;

use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class SalesMutation
{
    public static function run(callable $operation, ?string $statePath = null): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $exception) {
            self::notify($exception);
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[($statePath === null ? '' : $statePath.'.').$field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    public static function notify(ValidationException $exception): void
    {
        Notification::make()->title(__('Sales operation failed'))
            ->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
    }
}
