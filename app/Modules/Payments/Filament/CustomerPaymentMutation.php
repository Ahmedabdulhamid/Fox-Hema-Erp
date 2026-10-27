<?php

namespace App\Modules\Payments\Filament;

use App\Modules\Products\Filament\ProductMutation;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerPaymentMutation
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
                throw ValidationException::withMessages(['payment' => __('You are not authorized to perform this payment operation.')]);
            } catch (ValidationException $exception) {
                Notification::make()->title(__('Cannot complete payment'))->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
                throw $exception;
            }
        }, $statePath);
    }
}
