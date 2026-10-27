<?php

namespace App\Modules\Payments\Filament\Resources\CustomerRefunds\Pages;

use App\Modules\Payments\Filament\CustomerPaymentMutation;
use App\Modules\Payments\Filament\CustomerSettlementActions;
use App\Modules\Payments\Filament\Resources\CustomerRefunds\CustomerRefundResource;
use App\Modules\Payments\Services\CustomerRefunds;
use App\Modules\Payments\Services\PaymentsPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomerRefund extends ViewRecord
{
    protected static string $resource = CustomerRefundResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('post')->label(__('Post refund'))->visible(fn () => $this->record->status === 'draft' && CustomerSettlementActions::can(PaymentsPermissions::REFUNDS_POST, (int) $this->record->payment->location_id))->requiresConfirmation()
            ->action(function (): void {
                CustomerPaymentMutation::run(fn () => app(CustomerRefunds::class)->post(auth()->user(), $this->record));
                $this->redirect(CustomerRefundResource::getUrl('view', ['record' => $this->record]));
            }),
            Action::make('recover')->label(__('Record verified refund recovery'))
                ->visible(fn () => $this->record->status === 'posted' && $this->record->recovered_at === null && CustomerSettlementActions::can(PaymentsPermissions::REFUNDS_RECOVER, (int) $this->record->payment->location_id))
                ->requiresConfirmation()->modalDescription(__('Confirm the full refunded amount was actually returned to the original Treasury account.'))
                ->schema([CustomerSettlementActions::key(), Textarea::make('reason')->required()->maxLength(16000),
                    TextInput::make('reference')->label(__('Verified return reference'))->required()->maxLength(191)])
                ->action(function (array $data): void {
                    CustomerPaymentMutation::run(fn () => app(CustomerRefunds::class)->recover(auth()->user(), $this->record, $data['operation_key'], $data['reason'], $data['reference']));
                    $this->redirect(CustomerRefundResource::getUrl('view', ['record' => $this->record]));
                }),
        ];
    }
}
