<?php

namespace App\Modules\Purchasing\Filament;

use App\Models\MoneyFormatter;
use App\Modules\Purchasing\Filament\Resources\VendorPayments\VendorPaymentResource;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Models\VendorPaymentAllocation;
use App\Modules\Purchasing\Services\PurchaseSettlement;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Purchasing\Services\SupplierFinancialBalances;
use App\Modules\Purchasing\Services\SupplierFinancialOverview;
use App\Modules\Purchasing\Services\VendorPaymentAccess;
use App\Modules\Purchasing\Services\VendorPaymentAllocationService;
use Brick\Math\BigDecimal;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SettlementActions
{
    public static function allows(string $permission, ?int $locationId): bool
    {
        try {
            app(VendorPaymentAccess::class)->operation(auth()->user(), $permission, $locationId ?? 0);

            return true;
        } catch (AuthorizationException|HttpException) {
            return false;
        }
    }

    /** @return list<Action> */
    public static function bill(VendorBill $bill): array
    {
        $locationId = $bill->purchaseOrder?->location_id;

        return [
            Action::make('allocateAdvance')->label(__('Allocate existing advance'))
                ->visible(fn (): bool => $bill->status === 'posted' && self::allows(PurchasingPermissions::PAYMENTS_ALLOCATE, $locationId))
                ->schema([
                    Hidden::make('operation_key')->default(fn (): string => (string) Str::uuid7())->required(),
                    Select::make('payment_id')->label(__('Available advance'))->required()->searchable()
                        ->options(fn (): array => collect(app(SupplierFinancialOverview::class)->report(auth()->user(), (int) $bill->supplier_id, $locationId)['advances'])
                            ->mapWithKeys(fn ($payment): array => [$payment->id => $payment->payment_number.' | '.MoneyFormatter::format($payment->unallocated)])->all()),
                    TextInput::make('amount')->label(__('Allocation amount'))->inputMode('decimal')->required(),
                ])->action(function (Action $action, array $data) use ($bill): void {
                    self::run($action, fn () => app(VendorPaymentAllocationService::class)->allocate(auth()->user(), VendorPayment::findOrFail($data['payment_id']), $bill, $data['amount'], $data['operation_key']));
                }),
            Action::make('settleBill')->label(__('Settle bill'))->requiresConfirmation()
                ->modalDescription(__('Post a new payment from the explicitly selected Treasury source and allocate it to this bill.'))
                ->visible(fn (): bool => $bill->status === 'posted' && self::allows(PurchasingPermissions::PAYMENTS_CREATE, $locationId)
                    && self::allows(PurchasingPermissions::PAYMENTS_POST, $locationId) && self::allows(PurchasingPermissions::PAYMENTS_ALLOCATE, $locationId))
                ->fillForm(fn (): array => ['supplier_id' => $bill->supplier_id, 'location_id' => $locationId, 'payment_date' => now()->toDateString(),
                    'amount' => app(SupplierFinancialBalances::class)->bill(auth()->user(), $bill)['outstanding'],
                    'operation_key' => (string) Str::uuid7(), 'allocation_key' => (string) Str::uuid7()])
                ->schema(fn (Schema $schema): Schema => VendorPaymentResource::form($schema)->components([
                    ...VendorPaymentResource::form($schema)->getComponents(),
                    Hidden::make('operation_key')->required(), Hidden::make('allocation_key')->required(),
                ]))->action(function (Action $action, array $data) use ($bill): void {
                    $key = $data['operation_key'];
                    $allocationKey = $data['allocation_key'];
                    unset($data['operation_key'], $data['allocation_key']);
                    self::run($action, fn () => app(PurchaseSettlement::class)->pay(auth()->user(), $data, $key,
                        [['bill_id' => $bill->id, 'amount' => $data['amount'], 'operation_key' => $allocationKey]]));
                }),
        ];
    }

    /** @return list<Action> */
    public static function payment(VendorPayment $payment, ?Closure $displayAmounts = null): array
    {
        /** @var array{allocated: string, unallocated: string}|null $paymentAmounts */
        $paymentAmounts = null;
        $getPaymentAmounts = function () use ($payment, $displayAmounts, &$paymentAmounts): array {
            return $paymentAmounts ??= $displayAmounts === null
                ? app(SupplierFinancialBalances::class)->payment(auth()->user(), $payment)
                : $displayAmounts();
        };
        $isFullyAllocated = fn (): bool => BigDecimal::of($getPaymentAmounts()['unallocated'])->isZero();
        $billOptions = function (?string $search = null, mixed $billId = null) use ($payment): array {
            app(VendorPaymentAccess::class)->operation(auth()->user(), PurchasingPermissions::PAYMENTS_ALLOCATE, (int) $payment->location_id);

            return VendorBill::query()->where('supplier_id', $payment->supplier_id)->where('status', 'posted')
                ->whereHas('purchaseOrder', fn ($query) => $query->where('location_id', $payment->location_id)->where('supplier_id', $payment->supplier_id))
                ->whereRaw('vendor_bills.total_amount > COALESCE((
                    SELECT SUM(vendor_payment_allocations.amount)
                    FROM vendor_payment_allocations
                    INNER JOIN vendor_payments ON vendor_payments.id = vendor_payment_allocations.vendor_payment_id
                    WHERE vendor_payment_allocations.vendor_bill_id = vendor_bills.id
                        AND vendor_payment_allocations.released_at IS NULL
                        AND vendor_payments.status = ?
                        AND vendor_payments.voided_at IS NULL
                ), 0)', ['posted'])
                ->when($billId !== null, fn ($query) => $query->whereKey($billId))
                ->when(filled($search), fn ($query) => $query->where('bill_number', 'like', '%'.$search.'%'))
                ->orderByDesc('id')->limit(50)->pluck('bill_number', 'id')->all();
        };
        $allocationOptions = function (?string $search = null, mixed $allocationId = null) use ($payment): array {
            app(VendorPaymentAccess::class)->operation(auth()->user(), PurchasingPermissions::PAYMENTS_REVERSE, (int) $payment->location_id);

            return VendorPaymentAllocation::query()
                ->join('vendor_bills as bills', 'bills.id', '=', 'vendor_payment_allocations.vendor_bill_id')
                ->join('purchase_orders as orders', 'orders.id', '=', 'bills.purchase_order_id')
                ->where('vendor_payment_allocations.vendor_payment_id', $payment->id)
                ->whereNull('vendor_payment_allocations.released_at')
                ->where('bills.supplier_id', $payment->supplier_id)
                ->where('bills.status', 'posted')
                ->where('orders.location_id', $payment->location_id)
                ->where('orders.supplier_id', $payment->supplier_id)
                ->when($allocationId !== null, fn ($query) => $query->where('vendor_payment_allocations.id', $allocationId))
                ->when(filled($search), fn ($query) => $query->where('bills.bill_number', 'like', '%'.$search.'%'))
                ->orderByDesc('vendor_payment_allocations.id')->limit(50)
                ->get(['vendor_payment_allocations.id', 'vendor_payment_allocations.amount', 'bills.bill_number'])
                ->mapWithKeys(fn (VendorPaymentAllocation $allocation): array => [
                    $allocation->id => $allocation->bill_number.' | '.MoneyFormatter::format($allocation->amount),
                ])->all();
        };

        return [Action::make('allocateBills')->label(__('Allocate to bills'))->modal()
            ->visible(fn (): bool => $payment->status === 'posted' && $payment->voided_at === null && self::allows(PurchasingPermissions::PAYMENTS_ALLOCATE, $payment->location_id))
            ->disabled(fn (): bool => $isFullyAllocated())
            ->tooltip(fn (): ?string => $isFullyAllocated() ? __('This payment is fully allocated.') : null)
            ->modalDescription(fn (): string => $isFullyAllocated()
                ? __('This payment is fully allocated.')
                : __('Allocation associates paid funds with bills without moving cash again.'))
            ->schema([Repeater::make('allocations')->label(__('Bill allocations'))->minItems(1)->maxItems(200)->defaultItems(1)->schema([
                Select::make('bill_id')->label(__('Bill'))->searchable()->required()
                    ->getOptionLabelUsing(function (mixed $value) use ($billOptions): ?string {
                        return $billOptions(billId: $value)[$value] ?? null;
                    })->options(fn (): array => $billOptions())
                    ->getSearchResultsUsing(fn (string $search): array => $billOptions(search: $search)),
                TextInput::make('amount')->label(__('Allocation amount'))->inputMode('decimal')->required(),
                Hidden::make('operation_key')->default(fn (): string => (string) Str::uuid7())->required(),
            ])])->action(function (Action $action, array $data) use ($payment): void {
                self::run($action, function () use ($payment, $data): void {
                    if (BigDecimal::of(app(SupplierFinancialBalances::class)->payment(auth()->user(), $payment)['unallocated'])->isZero()) {
                        throw ValidationException::withMessages(['payment' => __('This payment is fully allocated.')]);
                    }

                    app(VendorPaymentAllocationService::class)->allocateMany(auth()->user(), $payment, array_values($data['allocations']));
                });
            }),
            Action::make('releaseAllocation')->label(__('Release allocation'))->modal()
                ->visible(fn (): bool => $payment->status === 'posted' && $payment->voided_at === null && self::allows(PurchasingPermissions::PAYMENTS_REVERSE, $payment->location_id))
                ->schema([
                    Select::make('allocation_id')->label(__('Allocation'))->required()->searchable()
                        ->getOptionLabelUsing(fn (mixed $value): ?string => $allocationOptions(allocationId: $value)[$value] ?? null)
                        ->options(fn (): array => $allocationOptions())
                        ->getSearchResultsUsing(fn (string $search): array => $allocationOptions(search: $search)),
                    Hidden::make('operation_key')->default(fn (): string => (string) Str::uuid7())->required(),
                    Textarea::make('reason')->label(__('Reason'))->required()->maxLength(15000),
                ])->action(function (Action $action, array $data) use ($payment): void {
                    app(VendorPaymentAccess::class)->operation(auth()->user(), PurchasingPermissions::PAYMENTS_REVERSE, (int) $payment->location_id);
                    $allocation = VendorPaymentAllocation::query()->whereKey($data['allocation_id'])->where('vendor_payment_id', $payment->id)
                        ->whereNull('released_at')->whereHas('vendorBill', fn ($query) => $query->where('supplier_id', $payment->supplier_id)
                        ->where('status', 'posted')->whereHas('purchaseOrder', fn ($query) => $query
                        ->where('location_id', $payment->location_id)->where('supplier_id', $payment->supplier_id)))->firstOrFail();

                    self::run($action, fn () => app(VendorPaymentAllocationService::class)->release(auth()->user(),
                        $allocation, $data['operation_key'], $data['reason']));
                }),
        ];
    }

    private static function run(Action $action, Closure $operation): void
    {
        try {
            PaymentMutation::run($operation);
        } catch (ValidationException) {
            $action->halt();
        }
        Notification::make()->title(__('Settlement updated'))->success()->send();
    }
}
