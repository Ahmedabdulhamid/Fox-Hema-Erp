<?php

namespace App\Modules\Sales\Filament;

use App\Modules\Sales\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SalesOrderActions
{
    /** @return list<Action> */
    public static function make(): array
    {
        $actions = [];
        foreach (['confirm' => 'Confirm Sales Order', 'cancel' => 'Cancel Sales Order'] as $operation => $label) {
            $actions[] = Action::make($operation)->label(__($label))->authorize($operation)->requiresConfirmation()
                ->visible(fn (SalesOrder $record): bool => auth()->user()?->can($operation, $record) ?? false)
                ->color($operation === 'confirm' ? 'success' : 'danger')
                ->modalDescription(__($operation === 'confirm'
                    ? 'Confirm saved order values and reserve all quantities at this branch.'
                    : 'Cancel this order and release its remaining reservations. On-hand stock is unchanged.'))
                ->schema([Hidden::make('revision')->default(fn (SalesOrder $record): string => app(SalesOrderService::class)->revision($record))])
                ->action(function (SalesOrder $record, array $data, Component $livewire, Action $action) use ($operation): void {
                    try {
                        app(SalesOrderConfirmationService::class)->{$operation}(auth()->user(), $record, $data['revision'] ?? null);
                    } catch (ValidationException $exception) {
                        Notification::make()->title(__('Sales order operation failed'))
                            ->body(implode(' ', array_merge(...array_values($exception->errors()))))->danger()->send();
                        $action->halt();
                    }
                    Notification::make()->title(__($operation === 'confirm'
                        ? 'Sales order confirmed and stock reserved.' : 'Sales order cancelled and reservations released.'))->success()->send();
                    $livewire->redirect(SalesOrderResource::getUrl('view', ['record' => $record]));
                });
        }

        return $actions;
    }
}
