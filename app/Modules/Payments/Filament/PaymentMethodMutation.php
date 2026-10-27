<?php

namespace App\Modules\Payments\Filament;

use App\Modules\Products\Filament\ProductMutation;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentMethodMutation
{
    public static function run(callable $operation, ?string $statePath = null): mixed
    {
        return ProductMutation::run(function () use ($operation): mixed {
            try {
                return $operation();
            } catch (AuthorizationException|HttpException $exception) {
                if ($exception instanceof HttpException && $exception->getStatusCode() !== 403) {
                    throw $exception;
                }
                throw ValidationException::withMessages(['method' => __('payment_methods.validation.denied')]);
            } catch (ValidationException $exception) {
                Notification::make()->title(__('payment_methods.notifications.failed'))
                    ->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
                throw $exception;
            }
        }, $statePath);
    }
}
