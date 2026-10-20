<?php

namespace App\Modules\Sales\Filament;

use App\Modules\Sales\Filament\Resources\SalesReturns\SalesReturnResource;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesReturnApprovalService;
use App\Modules\Sales\Services\SalesReturnPostingService;
use App\Modules\Sales\Services\SalesReturnService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SalesReturnActions
{
    public static function approve(): Action
    {
        return self::mutation('approve');
    }

    public static function cancel(): Action
    {
        return self::mutation('cancel');
    }

    public static function post(): Action
    {
        return self::mutation('post');
    }

    private static function mutation(string $operation): Action
    {
        return Action::make($operation)->label(__('returns.'.$operation))->authorize($operation)
            ->visible(fn (SalesReturn $record): bool => auth()->user()?->can($operation, $record) ?? false)
            ->requiresConfirmation()->color($operation === 'approve' ? 'success' : 'danger')
            ->modalDescription(__('returns.'.$operation.'_confirm'))
            ->schema([
                Hidden::make('revision')->default(fn (SalesReturn $record): string => app(SalesReturnService::class)->revision($record)),
                Textarea::make('reason')->label(__('returns.cancellation_reason'))->required($operation === 'cancel')
                    ->visible($operation === 'cancel')->maxLength(5000),
            ])->action(function (SalesReturn $record, array $data, Component $livewire, Action $action) use ($operation): void {
                try {
                    $service = app(SalesReturnApprovalService::class);
                    if ($operation === 'post') {
                        app(SalesReturnPostingService::class)->post(auth()->user(), $record, $data['revision'] ?? '');
                    } elseif ($operation === 'approve') {
                        $service->approve(auth()->user(), $record, $data['revision'] ?? '');
                    } else {
                        $service->cancel(auth()->user(), $record, $data['revision'] ?? '', $data['reason'] ?? '');
                    }
                } catch (ValidationException $exception) {
                    Notification::make()->title(__('returns.operation_failed'))->body(implode(' ', array_merge(...array_values($exception->errors()))))->danger()->send();
                    $action->halt();
                } catch (\Throwable $exception) {
                    report($exception);
                    Notification::make()->title(__('returns.operation_failed'))->danger()->send();
                    $action->halt();
                }
                Notification::make()->title(__('returns.operation_done'))->success()->send();
                $livewire->redirect(SalesReturnResource::getUrl('view', ['record' => $record]));
            });
    }
}
