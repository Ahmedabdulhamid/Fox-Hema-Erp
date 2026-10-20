<?php

namespace App\Modules\Sales\Filament;

use App\Modules\Sales\Filament\Resources\SalesDeliveries\SalesDeliveryResource;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SalesDeliveryActions
{
    public static function post(): Action
    {
        return Action::make('post')->label(__('Post Delivery'))->authorize('post')->color('success')->requiresConfirmation()
            ->visible(fn (SalesDelivery $record): bool => auth()->user()?->can('post', $record) ?? false)
            ->modalDescription(__('Post this delivery, consume its owned reservations and record inventory COGS.'))
            ->schema([Hidden::make('revision')->default(fn (SalesDelivery $record): string => app(SalesDeliveryService::class)->revision($record))])
            ->action(function (SalesDelivery $record, array $data, Component $livewire, Action $action): void {
                try {
                    app(SalesDeliveryPostingService::class)->post(auth()->user(), $record, $data['revision'] ?? '');
                } catch (ValidationException $exception) {
                    Notification::make()->title(__('Delivery posting failed'))->body(implode(' ', array_merge(...array_values($exception->errors()))))->danger()->send();
                    $action->halt();
                } catch (\Throwable $exception) {
                    report($exception);
                    Notification::make()->title(__('Delivery posting failed'))->body(__('Delivery posting was rolled back. Reload and try again.'))->danger()->send();
                    $action->halt();
                }
                Notification::make()->title(__('Delivery posted successfully.'))->success()->send();
                $livewire->redirect(SalesDeliveryResource::getUrl('view', ['record' => $record]));
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')->label(__('Cancel delivery draft'))->authorize('cancel')->color('danger')->requiresConfirmation()
            ->visible(fn (SalesDelivery $record): bool => auth()->user()?->can('cancel', $record) ?? false)
            ->modalDescription(__('Cancel this draft and retain its history. Stock and reservations remain unchanged.'))
            ->schema([Hidden::make('revision')->default(fn (SalesDelivery $record): string => app(SalesDeliveryService::class)->revision($record))])
            ->action(function (SalesDelivery $record, array $data, Component $livewire, Action $action): void {
                try {
                    app(SalesDeliveryService::class)->cancel(auth()->user(), $record, $data['revision'] ?? '');
                } catch (ValidationException $exception) {
                    Notification::make()->title(__('Delivery operation failed'))->body(implode(' ', array_merge(...array_values($exception->errors()))))->danger()->send();
                    $action->halt();
                }
                Notification::make()->title(__('Delivery draft cancelled.'))->success()->send();
                $livewire->redirect(SalesDeliveryResource::getUrl('view', ['record' => $record]));
            });
    }
}
