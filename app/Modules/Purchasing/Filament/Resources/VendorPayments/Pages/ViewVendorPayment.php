<?php

namespace App\Modules\Purchasing\Filament\Resources\VendorPayments\Pages;

use App\Models\MoneyFormatter;
use App\Modules\Purchasing\Filament\PaymentMutation;
use App\Modules\Purchasing\Filament\Resources\VendorPayments\VendorPaymentResource;
use App\Modules\Purchasing\Filament\SettlementActions;
use App\Modules\Purchasing\Services\SupplierFinancialBalances;
use App\Modules\Purchasing\Services\VendorPaymentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ViewVendorPayment extends ViewRecord
{
    protected static string $resource = VendorPaymentResource::class;

    /** @var array{allocated: string, unallocated: string}|null */
    protected ?array $paymentDisplayAmounts = null;

    /** @return array{allocated: string, unallocated: string} */
    protected function getPaymentDisplayAmounts(): array
    {
        $balances = app(SupplierFinancialBalances::class);
        if (! request()->isMethod('GET')) {
            return $balances->payment(auth()->user(), $this->record);
        }

        return $this->paymentDisplayAmounts ??= $balances->paymentForDisplay(auth()->user(), $this->record);
    }

    public function infolist(Schema $schema): Schema
    {
        return VendorPaymentResource::infolist($schema, fn (): array => $this->getPaymentDisplayAmounts());
    }

    protected function getHeaderActions(): array
    {
        return [
            ...SettlementActions::payment($this->record, fn (): array => $this->getPaymentDisplayAmounts()),
            Action::make('post')->label(__('Post vendor payment'))->authorize('post')->visible(fn (): bool => $this->record->status === 'draft' && $this->record->treasury_account_id !== null)
                ->requiresConfirmation()->modalDescription(fn (): string => __('Disburse the saved payment amount from the selected Treasury account?').' '.MoneyFormatter::format($this->record->amount))
                ->action(function (Action $action): void {
                    try {
                        PaymentMutation::run(fn () => app(VendorPaymentService::class)->post(auth()->user(), $this->record));
                    } catch (ValidationException) {
                        $action->halt();
                    }
                    Notification::make()->title(__('Vendor payment posted'))->success()->send();
                    $this->redirect(VendorPaymentResource::getUrl('view', ['record' => $this->record]));
                }),
            Action::make('recover')->label(__('Record verified full recovery'))->authorize('recover')->visible(fn (): bool => $this->record->status === 'posted' && $this->record->treasury_account_id !== null)
                ->requiresConfirmation()->modalDescription(__('Confirm only after the full amount has been verified as returned to the original Treasury account. A document void alone is insufficient.'))
                ->schema([
                    Hidden::make('operation_key')->default(fn (): string => (string) Str::uuid7())->required(),
                    TextInput::make('recovery_reference')->label(__('Verified recovery reference'))->required()->maxLength(191),
                    Textarea::make('reason')->label(__('Recovery reason'))->required()->maxLength(15000),
                ])->action(function (Action $action, array $data): void {
                    try {
                        PaymentMutation::run(fn () => app(VendorPaymentService::class)->recover(auth()->user(), $this->record, $data['operation_key'], $data['reason'], $data['recovery_reference']));
                    } catch (ValidationException) {
                        $action->halt();
                    }
                    Notification::make()->title(__('Vendor payment recovery recorded'))->success()->send();
                    $this->redirect(VendorPaymentResource::getUrl('view', ['record' => $this->record]));
                }),
        ];
    }
}
