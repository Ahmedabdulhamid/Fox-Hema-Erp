<?php

namespace App\Modules\Sales\Filament;

use App\Modules\Sales\Filament\Resources\SalesInvoices\SalesInvoiceResource;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SalesInvoiceActions
{
    public static function post(): Action
    {
        return self::mutation('post');
    }

    public static function cancel(): Action
    {
        return self::mutation('cancel');
    }

    public static function download(string $locale): Action
    {
        return Action::make('download_'.$locale)->label(__('invoices.download_'.$locale))->authorize('download')
            ->visible(fn (SalesInvoice $record): bool => auth()->user()?->can('download', $record) ?? false)
            ->url(fn (SalesInvoice $record): string => route('sales.invoices.pdf', ['invoice' => $record->uuid, 'locale' => $locale]));
    }

    private static function mutation(string $operation): Action
    {
        return Action::make($operation)->label(__('invoices.'.$operation))->authorize($operation)
            ->color($operation === 'post' ? 'success' : 'danger')->requiresConfirmation()
            ->visible(fn (SalesInvoice $record): bool => auth()->user()?->can($operation, $record) ?? false)
            ->modalDescription(__('invoices.'.$operation.'_confirm'))
            ->schema([Hidden::make('revision')->default(fn (SalesInvoice $record): string => app(SalesInvoiceService::class)->revision($record))])
            ->action(function (SalesInvoice $record, array $data, Component $livewire, Action $action) use ($operation): void {
                try {
                    app(SalesInvoiceService::class)->{$operation}(auth()->user(), $record, $data['revision'] ?? '');
                } catch (ValidationException $exception) {
                    Notification::make()->title(__('invoices.operation_failed'))->body(implode(' ', array_merge(...array_values($exception->errors()))))->danger()->send();
                    $action->halt();
                } catch (\Throwable $exception) {
                    report($exception);
                    Notification::make()->title(__('invoices.operation_failed'))->danger()->send();
                    $action->halt();
                }
                Notification::make()->title(__('invoices.operation_done'))->success()->send();
                $livewire->redirect(SalesInvoiceResource::getUrl('view', ['record' => $record]));
            });
    }
}
