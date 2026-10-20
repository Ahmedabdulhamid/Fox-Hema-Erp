<?php

namespace App\Modules\Products\Filament;

use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class ProductMutation
{
    public static function run(callable $mutation, ?string $statePath = null): mixed
    {
        try {
            return $mutation();
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['record'])) {
                Notification::make()->title(__($statePath === null ? 'Cannot delete this record' : 'Cannot save this record'))->body(implode(' ', $exception->errors()['record']))->danger()->send();
            }
            if ($statePath === null) {
                throw $exception;
            }
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$statePath.'.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }
}
