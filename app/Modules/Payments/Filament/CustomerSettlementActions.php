<?php

namespace App\Modules\Payments\Filament;

use App\Models\MoneyFormatter;
use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Models\CustomerInvoiceAccount;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Payments\Services\CustomerAccounts;
use App\Modules\Payments\Services\CustomerAllocations;
use App\Modules\Payments\Services\CustomerFinancials;
use App\Modules\Payments\Services\CustomerPayments;
use App\Modules\Payments\Services\CustomerRefunds;
use App\Modules\Payments\Services\CustomerTreasury;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesAccess;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

class CustomerSettlementActions
{
    public static function key(): Hidden
    {
        return Hidden::make('operation_key')->default(fn () => (string) Str::uuid7())->required();
    }

    public static function can(string $permission, ?int $location = null): bool
    {
        return app(CustomerAccess::class)->ready() && app(CustomerAccess::class)->allows(auth()->user(), $permission, $location);
    }

    public static function receiveInvoicePayment(): Action
    {
        return Action::make('receivePayment')->label(__('payments.receive_payment'))
            ->visible(fn (SalesInvoice $record): bool => $record->status === 'posted' && $record->cancelled_at === null
                && self::can(PaymentsPermissions::CREATE, (int) $record->location_id)
                && self::can(PaymentsPermissions::POST, (int) $record->location_id)
                && self::can(PaymentsPermissions::ALLOCATE, (int) $record->location_id)
                && BigDecimal::of(app(CustomerFinancials::class)->invoice(auth()->user(), $record)['outstanding'])->isPositive())
            ->fillForm(fn (SalesInvoice $record): array => ['operation_key' => (string) Str::uuid7(), 'payment_date' => now()->toDateString(),
                'amount' => app(CustomerFinancials::class)->invoice(auth()->user(), $record)['outstanding']])
            ->schema([
                self::key(),
                Select::make('payment_method_id')->label(__('payments.fields.payment_method_id'))->options(fn () => app(CustomerTreasury::class)->methods()->pluck('name', 'id'))->required()->live()
                    ->afterStateUpdated(fn (Set $set) => $set('treasury_account_id', null)),
                Select::make('treasury_account_id')->label(__('payments.fields.treasury_account_id'))
                    ->options(fn (SalesInvoice $record, Get $get): array => Resources\CustomerPayments\CustomerPaymentResource::accountsForLocation((int) $record->location_id, $get('payment_method_id')))->required()->searchable(),
                TextInput::make('amount')->label(__('payments.fields.amount'))->inputMode('decimal')->required()->suffix(__('payments.currency')),
                TextInput::make('external_reference')->label(__('payments.fields.external_reference'))->maxLength(191)
                    ->required(fn (Get $get): bool => (bool) PaymentMethod::whereKey($get('payment_method_id'))->value('requires_reference')),
                DatePicker::make('payment_date')->label(__('payments.fields.payment_date'))->maxDate(now())->required(),
            ])->modalDescription(__('payments.payment_confirmation_help'))->modalSubmitActionLabel(__('payments.receive_payment'))
            ->action(function (SalesInvoice $record, array $data): void {
                CustomerPaymentMutation::run(fn () => app(CustomerPayments::class)->payInvoice(auth()->user(), $record,
                    array_diff_key($data, ['operation_key' => true]), $data['operation_key']));
            });
    }

    public static function refundCreditNote(): Action
    {
        return Action::make('refundCreditNote')->label(__('payments.refund_credit'))
            ->visible(fn (SalesCreditNote $record): bool => $record->status === 'posted' && $record->cancelled_at === null
                && self::can(PaymentsPermissions::REFUNDS_CREATE, (int) $record->location_id)
                && self::can(PaymentsPermissions::REFUNDS_POST, (int) $record->location_id)
                && BigDecimal::of(app(CustomerRefunds::class)->creditBalance(auth()->user(), $record)['remaining'])->isPositive())
            ->fillForm(fn (): array => ['operation_key' => (string) Str::uuid7(), 'refund_date' => now()->toDateString(), 'reason' => __('payments.credit_refund_reason')])
            ->schema([
                self::key(),
                Select::make('payment_id')->label(__('payments.original_receipt'))->required()->live()
                    ->options(fn (SalesCreditNote $record) => Resources\CustomerPayments\CustomerPaymentResource::getEloquentQuery()->where('status', 'posted')
                        ->whereHas('allocations', fn ($query) => $query->where('sales_invoice_id', $record->sales_invoice_id))->get()
                        ->mapWithKeys(fn (Payment $payment) => [$payment->id => $payment->payment_number.' | '.$payment->paymentMethod->name.' | '.app(CustomerRefunds::class)->refundableAmount(auth()->user(), $record, $payment).' '.__('payments.currency')]))
                    ->afterStateUpdated(function (SalesCreditNote $record, Get $get, Set $set): void {
                        $payment = Resources\CustomerPayments\CustomerPaymentResource::getEloquentQuery()->whereHas('allocations', fn ($query) => $query->where('sales_invoice_id', $record->sales_invoice_id))->find($get('payment_id'));
                        $set('amount', $payment === null ? null : app(CustomerRefunds::class)->refundableAmount(auth()->user(), $record, $payment));
                        $set('external_reference', null);
                    }),
                TextInput::make('amount')->label(__('payments.fields.amount'))->inputMode('decimal')->required()->suffix(__('payments.currency')),
                TextInput::make('external_reference')->label(__('payments.fields.external_reference'))->maxLength(191),
                DatePicker::make('refund_date')->label(__('payments.fields.refund_date'))->maxDate(now())->required(),
                Textarea::make('reason')->label(__('payments.fields.reason'))->required()->maxLength(16000),
            ])->modalDescription(__('payments.refund_account_help'))->modalSubmitActionLabel(__('payments.refund_credit'))
            ->action(function (SalesCreditNote $record, array $data): void {
                CustomerPaymentMutation::run(fn () => app(CustomerRefunds::class)->refundCreditNote(auth()->user(), $record, array_diff_key($data, ['operation_key' => true]), $data['operation_key']));
                Notification::make()->title(__('payments.refund_posted'))->success()->send();
            });
    }

    public static function postPayment(): Action
    {
        return Action::make('post')->label(__('Post payment'))->visible(fn (Payment $record) => $record->status === 'draft' && self::can(PaymentsPermissions::POST, (int) $record->location_id))->requiresConfirmation()
            ->action(function (Payment $record, $livewire): void {
                CustomerPaymentMutation::run(fn () => app(CustomerPayments::class)->post(auth()->user(), $record));
                $livewire->redirect(Resources\CustomerPayments\CustomerPaymentResource::getUrl('view', ['record' => $record]));
            });
    }

    public static function linkInvoice(bool $fromInvoice = false): Action
    {
        return Action::make('linkCustomerInvoice')->label(__('Link customer invoice'))->visible(fn ($record) => self::can(PaymentsPermissions::ALLOCATE, (int) $record->location_id) && (! $fromInvoice || ($record->status === 'posted' && ! CustomerInvoiceAccount::where('sales_invoice_id', $record->id)->exists()
                && ! Payment::where('source_document_type', 'sales_invoice')->where('source_document_reference', $record->uuid)->exists())))
            ->schema([
                self::key(),
                Select::make('customer_id')->label(__('payments.fields.customer_id'))->options(fn () => Resources\CustomerPayments\CustomerPaymentResource::customers())->searchable()->required()->visible($fromInvoice),
                Select::make('sales_invoice_id')->label(__('Invoice'))->options(fn ($record) => app(SalesAccess::class)->scopeInvoices(SalesInvoice::query(), auth()->user())->where('location_id', $record->location_id)->where('status', 'posted')->whereNull('cancelled_at')->whereNotIn('id', CustomerInvoiceAccount::select('sales_invoice_id'))->pluck('invoice_number', 'id'))->searchable()->required()->visible(! $fromInvoice),
                Select::make('settlement_terms')->label(__('Settlement terms'))->options(['cash' => __('Cash'), 'credit' => __('Credit'), 'mixed' => __('Mixed')]),
                TextInput::make('planned_immediate_amount')->label(__('Planned immediate amount'))->inputMode('decimal')->helperText(__('Commercial intent only; paid amounts come from posted allocations.')),
                DatePicker::make('due_date')->label(__('payments.fields.due_date')),
            ])->action(function ($record, array $data) use ($fromInvoice): void {
                CustomerPaymentMutation::run(fn () => app(CustomerAccounts::class)->linkInvoice(auth()->user(), $fromInvoice ? Customer::findOrFail($data['customer_id']) : $record,
                    $fromInvoice ? $record : SalesInvoice::findOrFail($data['sales_invoice_id']),
                    array_intersect_key($data, array_flip(['settlement_terms', 'planned_immediate_amount', 'due_date'])), $data['operation_key']));
            });
    }

    public static function allocate(bool $choosePayment = false): Action
    {
        return Action::make('allocate')->label(__('Allocate payment'))->visible(fn ($record = null) => self::can(PaymentsPermissions::ALLOCATE, $record instanceof Payment ? (int) $record->location_id : null) && (! $record instanceof Payment || ($record->status === 'posted' && $record->customer_id !== null)))
            ->schema([
                self::key(),
                Select::make('payment_id')->label(__('payments.fields.payment_id'))->options(fn () => Resources\CustomerPayments\CustomerPaymentResource::getEloquentQuery()->where('status', 'posted')->pluck('payment_number', 'id'))->searchable()->required()->live()->visible($choosePayment),
                Select::make('sales_invoice_id')->label(__('Outstanding invoice'))->options(function (Get $get, $record = null): array {
                    $payment = $record instanceof Payment ? $record : Resources\CustomerPayments\CustomerPaymentResource::getEloquentQuery()->find($get('payment_id'));
                    if ($payment === null) {
                        return [];
                    }
                    $invoices = app(SalesAccess::class)->scopeInvoices(SalesInvoice::query(), auth()->user())->whereIn('id', CustomerInvoiceAccount::where('customer_id', $payment->customer_id)->select('sales_invoice_id'))->where('status', 'posted')->whereNull('cancelled_at')->get();
                    $options = [];
                    foreach ($invoices as $invoice) {
                        $balance = app(CustomerFinancials::class)->invoice(auth()->user(), $invoice);
                        if (BigDecimal::of($balance['outstanding'])->isPositive()) {
                            $options[$invoice->id] = $invoice->invoice_number.' | '.MoneyFormatter::format($balance['outstanding']);
                        }
                    }

                    return $options;
                })->searchable()->required(),
                TextInput::make('amount')->label(__('payments.fields.amount'))->required()->inputMode('decimal')->suffix(__('payments.currency')),
            ])->action(fn (array $data, $record = null) => CustomerPaymentMutation::run(fn () => app(CustomerAllocations::class)->allocate(auth()->user(),
                $record instanceof Payment ? $record : Payment::findOrFail($data['payment_id']), SalesInvoice::findOrFail($data['sales_invoice_id']), $data['amount'], $data['operation_key'])));
    }

    public static function release(): Action
    {
        return Action::make('release')->label(__('Release allocation'))->visible(fn (PaymentAllocation $record) => $record->released_at === null && $record->payment->customer_id !== null && self::can(PaymentsPermissions::ALLOCATIONS_RELEASE, (int) $record->payment->location_id))
            ->schema([self::key(), Textarea::make('reason')->required()->maxLength(16000)])->action(fn (PaymentAllocation $record, array $data) => CustomerPaymentMutation::run(fn () => app(CustomerAllocations::class)->release(auth()->user(), $record, $data['operation_key'], $data['reason'])));
    }
}
