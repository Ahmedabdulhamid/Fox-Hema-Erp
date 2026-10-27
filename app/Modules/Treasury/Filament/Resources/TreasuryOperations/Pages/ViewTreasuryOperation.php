<?php

namespace App\Modules\Treasury\Filament\Resources\TreasuryOperations\Pages;

use App\Models\MoneyFormatter;
use App\Modules\Treasury\Filament\Resources\TreasuryOperations\TreasuryOperationResource;
use App\Modules\Treasury\Filament\TreasuryMutation;
use App\Modules\Treasury\Services\TreasuryService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ViewTreasuryOperation extends ViewRecord
{
    protected static string $resource = TreasuryOperationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('post')->label(__('Post voucher'))->authorize('post')->requiresConfirmation()
                ->modalDescription(fn (): string => __('Post this saved voucher to the selected Treasury accounts?').' '.MoneyFormatter::format($this->record->amount))
                ->action(fn (Action $action) => $this->mutate($action, fn () => app(TreasuryService::class)->post(auth()->user(), $this->record))),
            ...array_map(fn (string $operation): Action => Action::make($operation)->label(__($operation === 'cancel' ? 'Cancel draft' : 'Record compensating reversal'))->authorize($operation)->requiresConfirmation()
                ->modalDescription(__($operation === 'cancel' ? 'Cancel only this unposted draft. No funds move.' : 'Record a verified correction with equal opposite movements. Supplier recovery must use Purchasing.'))
                ->schema([
                    Hidden::make('operation_key')->default(fn (): string => (string) Str::uuid7())->required(),
                    Textarea::make('reason')->label(__('Reason'))->required()->maxLength(16000),
                ])->action(fn (Action $action, array $data) => $this->mutate($action, fn () => app(TreasuryService::class)->{$operation}(auth()->user(), $this->record, $data['operation_key'], $data['reason']))),
                ['cancel', 'reverse']),
        ];
    }

    private function mutate(Action $action, \Closure $operation): void
    {
        try {
            TreasuryMutation::run($operation);
        } catch (ValidationException) {
            $action->halt();
        }
        Notification::make()->title(__('Treasury operation completed'))->success()->send();
        $this->redirect(TreasuryOperationResource::getUrl('view', ['record' => $this->record]));
    }
}
