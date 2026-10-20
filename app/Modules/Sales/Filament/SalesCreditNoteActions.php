<?php

namespace App\Modules\Sales\Filament;

use App\Modules\Sales\Filament\Resources\SalesCreditNotes\SalesCreditNoteResource;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Services\SalesCreditNoteService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SalesCreditNoteActions
{
    public static function post(): Action
    {
        return self::mutation('post');
    }

    public static function download(string $locale): Action
    {
        return Action::make('download_'.$locale)->label(__('credits.download_'.$locale))->authorize('download')
            ->visible(fn (SalesCreditNote $record): bool => auth()->user()?->can('download', $record) ?? false)
            ->url(fn (SalesCreditNote $record): string => route('sales.credit-notes.pdf', ['note' => $record->uuid, 'locale' => $locale]));
    }

    private static function mutation(string $operation): Action
    {
        return Action::make($operation)->label(__('credits.'.$operation))->authorize($operation)
            ->color($operation === 'post' ? 'success' : 'danger')->requiresConfirmation()
            ->visible(fn (SalesCreditNote $record): bool => auth()->user()?->can($operation, $record) ?? false)
            ->modalDescription(__('credits.'.$operation.'_confirm'))
            ->schema([Hidden::make('revision')->default(fn (SalesCreditNote $record): string => app(SalesCreditNoteService::class)->revision($record))])
            ->action(function (SalesCreditNote $record, array $data, Component $livewire, Action $action) use ($operation): void {
                try {
                    app(SalesCreditNoteService::class)->{$operation}(auth()->user(), $record, $data['revision'] ?? '');
                } catch (ValidationException $exception) {
                    Notification::make()->title(__('credits.operation_failed'))->body(implode(' ', array_merge(...array_values($exception->errors()))))->danger()->send();
                    $action->halt();
                } catch (\Throwable $exception) {
                    report($exception);
                    Notification::make()->title(__('credits.operation_failed'))->danger()->send();
                    $action->halt();
                }
                Notification::make()->title(__('credits.operation_done'))->success()->send();
                $livewire->redirect(SalesCreditNoteResource::getUrl('view', ['record' => $record]));
            });
    }
}
