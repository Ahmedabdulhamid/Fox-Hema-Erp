<?php

namespace App\Modules\Purchasing\Filament;

use App\Modules\Products\Filament\ProductMutation;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentMutation
{
    public static function run(callable $operation, ?string $statePath = null): mixed
    {
        return ProductMutation::run(function () use ($operation): mixed {
            try {
                return $operation();
            } catch (ValidationException $exception) {
                Notification::make()->title(__('Cannot complete vendor payment'))->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
                throw $exception;
            } catch (AuthorizationException|HttpException $exception) {
                if ($exception instanceof HttpException && $exception->getStatusCode() !== 403) {
                    throw $exception;
                }
                $message = __('You are not authorized to use this Treasury account or perform this payment operation.');
                Notification::make()->title(__('Cannot complete vendor payment'))->body($message)->danger()->send();
                throw ValidationException::withMessages(['payment' => $message]);
            }
        }, $statePath);
    }
}
