<?php

namespace App\Modules\Treasury\Filament;

use App\Modules\Products\Filament\ProductMutation;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TreasuryMutation
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
                $message = __('You are not authorized to perform this Treasury operation.');
                Notification::make()->title(__('Treasury operation failed'))->body($message)->danger()->send();
                throw ValidationException::withMessages(['operation' => $message]);
            } catch (ValidationException $exception) {
                Notification::make()->title(__('Treasury operation failed'))->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
                throw $exception;
            }
        }, $statePath);
    }
}
