<?php

namespace App\Modules\Purchasing\Filament;

use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class BillMutation
{
    public static function run(callable $operation, ?string $statePath = null): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $exception) {
            Notification::make()->title(__('Cannot save or post bill'))->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[($statePath === null ? '' : $statePath.'.').$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        } catch (AuthorizationException|HttpException $exception) {
            if ($exception instanceof HttpException && $exception->getStatusCode() !== 403) {
                report($exception);
            }
            $message = __($exception instanceof AuthorizationException || $exception->getStatusCode() === 403
                ? 'You are not authorized to perform this bill operation.'
                : 'The bill operation failed. Refresh and try again, or contact your administrator.');
            Notification::make()->title(__('Cannot save or post bill'))->body($message)->danger()->send();
            throw ValidationException::withMessages([($statePath === null ? '' : $statePath.'.').'bill' => $message]);
        } catch (Throwable $exception) {
            report($exception);
            $message = __('The bill operation failed. Refresh and try again, or contact your administrator.');
            Notification::make()->title(__('Cannot save or post bill'))->body($message)->danger()->send();
            throw ValidationException::withMessages([($statePath === null ? '' : $statePath.'.').'bill' => $message]);
        }
    }
}
