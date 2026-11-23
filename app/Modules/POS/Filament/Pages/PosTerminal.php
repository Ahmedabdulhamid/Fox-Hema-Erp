<?php

namespace App\Modules\POS\Filament\Pages;

use App\Http\Middleware\BindPosOfflineContext;
use App\Modules\AccessManagement\Filament\Pages\Dashboard;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Payments\Filament\CustomerPaymentMutation;
use App\Modules\Payments\Filament\CustomerSettlementActions;
use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Payments\Services\CustomerRefunds;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\POS\Data\PosContext;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\POS\Rules\WholeQuantity;
use App\Modules\POS\Services\PosAccess;
use App\Modules\POS\Services\PosCheckoutService;
use App\Modules\POS\Services\PosOfflineAuthorizationLease;
use App\Modules\POS\Services\PosOfflineReturnSources;
use App\Modules\POS\Services\PosPermissions;
use App\Modules\POS\Services\PosReadModels;
use App\Modules\POS\Services\PosTerminalCatalog;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesPermissions;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class PosTerminal extends Page
{
    use WithPagination;

    protected string $view = 'pos.terminal';

    protected static ?string $slug = 'pos-terminal';

    #[Locked]
    public ?int $locationId = null;

    #[Locked]
    public ?int $posSessionId = null;

    #[Locked]
    public string $clientTransactionId = '';

    #[Url(as: 'receipt', history: true)]
    public ?string $receiptUuid = null;

    public string $search = '';

    public ?int $categoryId = null;

    public ?int $brandId = null;

    public ?int $sizeId = null;

    public ?int $colorId = null;

    /** @var array<int, string> */
    public array $selectedVariantIds = [];

    /** @var array<int, array{quantity: int|string, discount_id: int|null}> */
    public array $cart = [];

    /** @var array<string, mixed> */
    public array $cartQuote = [
        'items' => [],
        'subtotal' => '0.0000',
        'discount_amount' => '0.0000',
        'tax_amount' => '0.0000',
        'total_amount' => '0.0000',
    ];

    /** @var array<int, array{amount: string, external_reference: string}> */
    public array $tenders = [];

    public string $cashReceived = '0.00';

    public string $customerSearch = '';

    public ?int $customerId = null;

    public ?string $customerName = null;

    public ?string $customerPhone = null;

    public bool $showCheckout = false;

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(PosAccess::class)->can($actor, PosPermissions::VIEW);
    }

    public static function getNavigationLabel(): string
    {
        return __('pos.terminal');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pos.navigation_group');
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-shopping-cart';
    }

    public static function getNavigationSort(): ?int
    {
        return 10;
    }

    public function getTitle(): string
    {
        return __('pos.terminal');
    }

    public function posCashRefundAction(): Action
    {
        return Action::make('posCashRefund')->label(__('pos.cash_refund.action'))->button()->color('success')
            ->icon('heroicon-o-banknotes')->extraAttributes(['class' => 'fox-pos-refund-action'])
            ->visible(fn (): bool => $this->currentSession !== null
                && app(PosAccess::class)->can($this->actor(), PosPermissions::RETURNS)
                && CustomerSettlementActions::can(PaymentsPermissions::REFUNDS_CREATE, $this->branchId())
                && CustomerSettlementActions::can(PaymentsPermissions::REFUNDS_POST, $this->branchId()))
            ->modalDescription(__('pos.cash_refund.notice'))
            ->schema([
                Hidden::make('operation_key')->default(fn (): string => (string) Str::uuid7())->required(),
                Select::make('source')->label(__('pos.cash_refund.source'))->options(fn (): array => $this->posCashRefundSources())->searchable()->required(),
                TextInput::make('amount')->label(__('payments.fields.amount'))->inputMode('decimal')->suffix(__('pos.currency'))->required(),
                Textarea::make('reason')->label(__('payments.fields.reason'))->required()->maxLength(16000),
            ])
            ->action(function (array $data): void {
                $this->authorizeCheckout();
                Validator::make($data, ['source' => ['required', 'regex:/^[1-9][0-9]*:[1-9][0-9]*$/']])->validate();
                [$creditId, $paymentId] = array_map('intval', explode(':', $data['source']));
                $payment = Payment::where('pos_session_id', $this->currentSession->id)->where('location_id', $this->branchId())->findOrFail($paymentId);
                $credit = SalesCreditNote::where('location_id', $this->branchId())->findOrFail($creditId);
                $refund = CustomerPaymentMutation::run(fn () => app(CustomerRefunds::class)->refundCreditNote($this->actor(), $credit, [
                    'payment_id' => $payment->id, 'amount' => $data['amount'], 'refund_date' => today()->toDateString(),
                    'external_reference' => null, 'reason' => $data['reason'],
                ], $data['operation_key']));
                Notification::make()->title(__('payments.refund_posted'))->body($refund->refund_number)->success()->send();
            });
    }

    /** @return array<string, string> */
    private function posCashRefundSources(): array
    {
        $this->authorizeCheckout();
        app(PosAccess::class)->authorize($this->actor(), PosPermissions::RETURNS, $this->branchId());
        $payments = Payment::where('pos_session_id', $this->currentSession->id)->where('created_by', $this->actor()->id)
            ->where('location_id', $this->branchId())->where('status', 'posted')->whereHas('paymentMethod', fn ($query) => $query->where('type', 'cash'))->get();
        $options = [];
        foreach ($payments as $payment) {
            $invoiceIds = PaymentAllocation::where('payment_id', $payment->id)->pluck('sales_invoice_id');
            foreach (SalesCreditNote::whereIn('sales_invoice_id', $invoiceIds)->where('location_id', $this->branchId())->where('status', 'posted')->whereNull('cancelled_at')->get() as $credit) {
                $amount = app(CustomerRefunds::class)->refundableAmount($this->actor(), $credit, $payment);
                if (BigDecimal::of($amount)->isPositive()) {
                    $options[$credit->id.':'.$payment->id] = $credit->credit_note_number.' / '.$payment->payment_number.' / '.$amount.' '.__('pos.currency');
                }
            }
        }

        return $options;
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    #[Computed]
    public function administrationUrl(): ?string
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $actor->is_active || $actor->must_change_password
            || ! $actor->hasPanelPermissions() || ! Dashboard::canAccess()) {
            return null;
        }

        return Dashboard::getUrl(panel: 'admin');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $locationId = getPermissionsTeamId();
        abort_unless($locationId !== null, 403);
        $this->locationId = (int) $locationId;
        $this->posSessionId = PosSession::query()
            ->where('location_id', $this->locationId)
            ->where('opened_by', auth()->id())
            ->where('status', 'open')
            ->whereNull('closing_started_at')
            ->whereNull('closed_at')
            ->value('id');
        $this->clientTransactionId = (string) Str::uuid7();
        $this->refreshCartQuote();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatedBrandId(): void
    {
        $this->resetPage();
    }

    public function updatedSizeId(): void
    {
        $this->resetPage();
    }

    public function updatedColorId(): void
    {
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        $field = explode('.', $property)[0];
        if (in_array($field, ['customerSearch', 'customerId', 'customerName', 'customerPhone', 'cashReceived', 'tenders', 'showCheckout'], true)) {
            $this->renderPayment();
        }
    }

    #[Computed]
    public function filters(): array
    {
        return app(PosTerminalCatalog::class)->filters($this->actor(), $this->branchId());
    }

    #[Computed]
    public function branchName(): ?string
    {
        return Location::query()->whereKey($this->branchId())->value('name');
    }

    #[Computed]
    public function canSelectCustomer(): bool
    {
        return app(PosAccess::class)->can($this->actor(), PosPermissions::CHECKOUT)
            && app(CustomerAccess::class)->allows($this->actor(), PaymentsPermissions::ALLOCATE, $this->branchId());
    }

    #[Computed]
    public function customers(): array
    {
        if (! $this->canSelectCustomer) {
            return [];
        }

        return app(PosTerminalCatalog::class)->customers($this->actor(), $this->branchId(), $this->customerSearch);
    }

    public function updatedCustomerId(?int $customerId): void
    {
        abort_unless(app(CustomerAccess::class)->allows($this->actor(), PaymentsPermissions::ALLOCATE, $this->branchId()), 403);

        $customer = $customerId === null
            ? null
            : Customer::query()
                ->whereKey($customerId)
                ->where('location_id', $this->branchId())
                ->where('is_active', true)
                ->first();

        abort_if($customerId !== null && $customer === null, 404);
        $this->customerName = $customer?->name;
        $this->customerPhone = $customer?->phone;
    }

    #[Computed]
    public function products(): array
    {
        return app(PosTerminalCatalog::class)->productPage($this->actor(), $this->branchId(), [
            'search' => $this->search,
            'category_id' => $this->categoryId,
            'brand_id' => $this->brandId,
            'size_id' => $this->sizeId,
            'color_id' => $this->colorId,
        ], $this->getPage());
    }

    #[Computed]
    public function currentSession(): ?PosSession
    {
        if ($this->posSessionId === null) {
            return null;
        }

        return PosSession::query()
            ->with(['location:id,name,code', 'posDevice:id,name,device_code,is_active,revoked_at'])
            ->whereKey($this->posSessionId)
            ->where('location_id', $this->branchId())
            ->where('opened_by', $this->actor()->id)
            ->where('status', 'open')
            ->whereNull('closing_started_at')
            ->whereNull('closed_at')
            ->whereHas('posDevice', fn ($query) => $query->where('is_active', true)->whereNull('revoked_at'))
            ->first();
    }

    #[Computed]
    public function checkoutMethods(): array
    {
        $session = $this->currentSession;
        if ($session === null || ! app(PosAccess::class)->can($this->actor(), PosPermissions::CHECKOUT)) {
            return [];
        }

        return app(PosReadModels::class)->checkoutTenderOptions(
            $this->actor(),
            (int) $session->pos_device_id,
            (int) $session->id,
        );
    }

    /** @return array{branch_id: int, device_id: int, session_id: int, cashier_id: int}|null */
    #[Computed]
    public function offlineScope(): ?array
    {
        $session = $this->currentSession;
        if ($session === null) {
            return null;
        }

        return [
            'branch_id' => (int) $session->location_id,
            'device_id' => (int) $session->pos_device_id,
            'session_id' => (int) $session->id,
            'cashier_id' => (int) $this->actor()->id,
        ];
    }

    /** @return array{scope: array{branch_id: int, device_id: int, session_id: int, cashier_id: int}, catalog: array<string, mixed>, payment_methods: list<array<string, mixed>>} */
    #[Renderless]
    public function offlineCatalogPage(int $page): array
    {
        abort_unless($page > 0 && $this->offlineScope !== null, 409);

        return [
            'scope' => $this->offlineScope,
            'context' => app(BindPosOfflineContext::class)->bind(request(), $this->offlineScope),
            'catalog' => app(PosTerminalCatalog::class)->offlineCatalogPage($this->actor(), $this->branchId(), $page),
            'payment_methods' => $this->checkoutMethods,
            'return_authorization' => app(PosOfflineAuthorizationLease::class)->issue($this->actor(), $this->offlineScope),
        ];
    }

    /**
     * @param  list<string>|null  $clientTransactionIds
     * @return array<string, mixed>
     */
    #[Renderless]
    public function offlineReturnSources(?array $clientTransactionIds = null, int $afterId = 0): array
    {
        $this->authorizeCheckout();
        Validator::make(['client_transaction_ids' => $clientTransactionIds, 'after_id' => $afterId], [
            'client_transaction_ids' => ['nullable', 'array', 'list', 'max:100'],
            'client_transaction_ids.*' => ['required', 'uuid', 'distinct'],
            'after_id' => ['required', 'integer', 'min:0'],
        ])->validate();
        app(PosAccess::class)->authorize($this->actor(), PosPermissions::RETURNS, $this->branchId());
        app(SalesAccess::class)->authorize($this->actor(), SalesPermissions::RETURNS_VIEW, $this->branchId());
        app(SalesAccess::class)->authorize($this->actor(), SalesPermissions::RETURNS_CREATE, $this->branchId());

        $transactions = PosTransaction::query()
            ->when($clientTransactionIds !== null, fn ($query) => $query->whereIn('client_transaction_id', $clientTransactionIds))
            ->where('id', '>', $afterId)
            ->where('pos_session_id', $this->posSessionId)
            ->where('pos_device_id', $this->currentSession->pos_device_id)
            ->where('location_id', $this->branchId())
            ->where('cashier_id', $this->actor()->id)
            ->where('transaction_type', 'sale')
            ->where('sync_status', 'completed')
            ->whereNotNull('sales_invoice_id')
            ->whereNotNull('sales_order_id')
            ->with('salesInvoice')
            ->orderBy('id')
            ->limit(101)
            ->get();
        $hasMore = $transactions->count() > 100;
        $transactions = $transactions->take(100);
        $invoices = $transactions->pluck('salesInvoice')->filter()->unique('id')->keyBy('id');
        $linesByInvoice = app(PosOfflineReturnSources::class)->forInvoices($invoices);

        return [
            'scope' => $this->offlineScope,
            'next_after_id' => $hasMore ? (int) $transactions->last()->id : null,
            'sales' => $transactions->map(fn (PosTransaction $transaction): array => [
                'client_transaction_id' => $transaction->client_transaction_id,
                'scope' => $this->offlineScope,
                'status' => 'completed',
                'quote' => ['total_amount' => $transaction->salesInvoice->total_amount],
                'result' => [
                    'invoice_number' => $transaction->salesInvoice->invoice_number,
                    'return_lines' => $linesByInvoice[$transaction->sales_invoice_id] ?? [],
                ],
            ])->values()->all(),
            'sources' => $transactions->mapWithKeys(fn (PosTransaction $transaction): array => [
                $transaction->client_transaction_id => $linesByInvoice[$transaction->sales_invoice_id] ?? [],
            ])->all(),
        ];
    }

    #[Computed]
    public function recentTransactions(): array
    {
        return PosTransaction::query()
            ->where('location_id', $this->branchId())
            ->where('cashier_id', $this->actor()->id)
            ->where('transaction_type', 'sale')
            ->where('sync_status', 'completed')
            ->whereNotNull('sales_invoice_id')
            ->with('salesInvoice:id,invoice_number,total_amount')
            ->orderByDesc('processed_at')
            ->limit(8)
            ->get(['id', 'uuid', 'sales_invoice_id', 'result_payload', 'processed_at'])
            ->map(fn (PosTransaction $transaction): array => [
                'uuid' => $transaction->uuid,
                'invoice_number' => $transaction->salesInvoice?->invoice_number,
                'total' => $transaction->salesInvoice?->total_amount,
                'processed_at' => $transaction->processed_at?->format('Y-m-d H:i'),
            ])
            ->all();
    }

    #[Computed]
    public function receipt(): ?array
    {
        if (! filled($this->receiptUuid)) {
            return null;
        }

        return DB::transaction(fn (): array => RequestAuthorizationFacts::duringLockedTransaction(function (): array {
            $transaction = PosTransaction::query()
                ->where('uuid', $this->receiptUuid)
                ->where('transaction_type', 'sale')
                ->where('sync_status', 'completed')
                ->firstOrFail();
            $context = PosContext::fromTransactionForPayment($this->actor(), $transaction, historical: true);
            $invoice = SalesInvoice::query()
                ->with([
                    'location:id,name,address,phone',
                    'items',
                ])
                ->whereKey($transaction->sales_invoice_id)
                ->firstOrFail();
            app(PosAccess::class)->invoice($this->actor(), $context, $invoice, historical: true);

            $payments = Payment::query()
                ->where('pos_transaction_id', $transaction->id)
                ->where('pos_session_id', $transaction->pos_session_id)
                ->where('status', 'posted')
                ->with('paymentMethod:id,name,type')
                ->orderBy('id')
                ->get()
                ->map(fn (Payment $payment): array => [
                    'method' => $payment->paymentMethod?->name,
                    'amount' => $payment->amount,
                    'reference' => $payment->external_reference,
                ])
                ->all();

            return [
                'invoice_number' => $invoice->invoice_number,
                'branch' => $invoice->location->name,
                'branch_address' => $invoice->location->address,
                'branch_phone' => $invoice->location->phone,
                'cashier' => $transaction->cashier()->value('name'),
                'date' => $invoice->posted_at?->format('Y-m-d H:i'),
                'items' => $invoice->items->map(fn ($item): array => [
                    'name' => $item->commercial_snapshot['product_name'] ?? $item->description,
                    'sku' => $item->commercial_snapshot['sku'] ?? null,
                    'size' => $item->commercial_snapshot['size'] ?? null,
                    'color' => $item->commercial_snapshot['color'] ?? null,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'discount' => $item->discount_amount,
                    'tax' => $item->tax_amount,
                    'total' => $item->line_total,
                ])->all(),
                'subtotal' => $invoice->subtotal,
                'discount_amount' => $invoice->discount_amount,
                'tax_amount' => $invoice->tax_amount,
                'total_amount' => $invoice->total_amount,
                'payments' => $payments,
                'change' => $transaction->result_payload['cash_change'] ?? '0.0000',
            ];
        }), 3);
    }

    public function selectVariant(int $productId, int $variantId): void
    {
        $product = collect($this->products['items'])->firstWhere('id', $productId);
        abort_unless($product !== null && collect($product['variants'])->contains('id', $variantId), 403);
        $this->selectedVariantIds[$productId] = (string) $variantId;
        $this->triggerImplicitIslandRender('catalog');
    }

    public function addProduct(int $productId): void
    {
        $this->authorizeCheckout();
        $this->renderCart();
        $selection = app(PosTerminalCatalog::class)->cartSelection(
            $this->actor(), $this->branchId(), $productId,
            isset($this->selectedVariantIds[$productId]) ? (int) $this->selectedVariantIds[$productId] : null,
            sizeId: $this->sizeId, colorId: $this->colorId,
        );
        $variantId = $selection['variant_id'];
        if (isset($this->cart[$variantId])) {
            Validator::make($this->cart[$variantId], ['quantity' => ['required', new WholeQuantity]])->validate();
        }
        $current = $this->cart[$variantId] ?? ['quantity' => 0, 'discount_id' => $selection['discount_id']];
        $this->cart[$variantId] = [
            'quantity' => (int) $current['quantity'] + 1,
            'discount_id' => $current['discount_id'],
        ];
        $this->refreshCartQuote();
    }

    public function changeQuantity(int $variantId, mixed $delta): void
    {
        $this->authorizeCheckout();
        $this->renderCart();
        abort_unless(in_array($delta, [-1, 1], true), 422);
        if (! isset($this->cart[$variantId])) {
            return;
        }

        Validator::make($this->cart[$variantId], ['quantity' => ['required', new WholeQuantity]])->validate();
        $quantity = (int) $this->cart[$variantId]['quantity'] + $delta;
        if ($quantity < 1) {
            return;
        } else {
            $this->cart[$variantId]['quantity'] = $quantity;
        }
        $this->refreshCartQuote();
    }

    public function setDiscount(int $variantId, int|string|null $discountId): void
    {
        $this->authorizeCheckout();
        $this->renderCart();
        abort_unless(isset($this->cart[$variantId]), 404);
        $discountId = $discountId === '' ? null : $discountId;
        Validator::make(['discount_id' => $discountId], ['discount_id' => ['nullable', 'integer', 'min:1']])->validate();
        $discountId = $discountId === null ? null : (int) $discountId;
        $item = collect($this->cartQuote['items'])->firstWhere('variant_id', $variantId);
        abort_unless($item !== null && ($discountId === null || collect($item['discounts'])->contains('id', $discountId)), 403);
        $this->cart[$variantId]['discount_id'] = $discountId;
        $this->refreshCartQuote();
    }

    public function removeFromCart(int $variantId): void
    {
        $this->authorizeCheckout();
        $this->renderCart();
        unset($this->cart[$variantId]);
        $this->refreshCartQuote();
    }

    public function clearCart(): void
    {
        $this->authorizeCheckout();
        $this->renderCart();
        $this->cart = [];
        $this->tenders = [];
        $this->refreshCartQuote();
    }

    public function openCheckout(): void
    {
        $this->authorizeCheckout();
        $this->renderCart();
        $this->renderPayment();
        $this->resetValidation();
        $this->refreshCartQuote();
        if ($this->cartQuote['items'] === []) {
            throw ValidationException::withMessages(['cart' => __('pos.validation.cart_empty')]);
        }
        $methods = $this->checkoutMethods;
        if ($methods === []) {
            throw ValidationException::withMessages(['tenders' => __('pos.validation.payment_configuration_missing')]);
        }

        $this->tenders = [];
        $method = collect($methods)->firstWhere('type', 'cash') ?? $methods[0];
        $this->tenders[$method['id']] = [
            'amount' => $this->cartQuote['total_amount'],
            'external_reference' => '',
        ];
        $this->cashReceived = $method['type'] === 'cash' ? $this->cartQuote['total_amount'] : '0.00';
        $this->showCheckout = true;
    }

    public function selectPaymentMethod(int $paymentMethodId): void
    {
        $this->authorizeCheckout();
        $this->renderPayment();
        $method = collect($this->checkoutMethods)->firstWhere('id', $paymentMethodId);
        abort_unless($method !== null, 403);
        $this->tenders = [$paymentMethodId => [
            'amount' => $this->cartQuote['total_amount'],
            'external_reference' => '',
        ]];
        $this->cashReceived = $method['type'] === 'cash' ? $this->cartQuote['total_amount'] : '0.00';
    }

    public function checkout(): void
    {
        $this->authorizeCheckout();
        $session = $this->currentSession;
        abort_unless($session !== null, 403);

        try {
            $transaction = app(PosCheckoutService::class)->checkout(
                $this->actor(),
                (int) $session->id,
                $this->clientTransactionId,
                [
                    'customer_id' => $this->customerId,
                    'customer_name' => $this->customerName,
                    'customer_phone' => $this->customerPhone,
                    'items' => collect($this->cart)->map(fn (array $line, int|string $variantId): array => [
                        'variant_id' => (int) $variantId,
                        'quantity' => $line['quantity'],
                        'discount_id' => $line['discount_id'],
                    ])->values()->all(),
                    'tenders' => collect($this->tenders)->map(fn (array $tender, int|string $methodId): array => [
                        'payment_method_id' => (int) $methodId,
                        'amount' => $tender['amount'],
                        'external_reference' => $tender['external_reference'] ?? null,
                    ])->values()->all(),
                    'cash_received' => $this->cashReceived,
                ],
                expectedQuote: $this->cartQuote,
            );
        } catch (ValidationException $exception) {
            if (array_key_exists('cart', $exception->errors())) {
                $this->showCheckout = false;
                $this->tenders = [];
                $this->refreshCartQuote();
            }
            $this->renderCart();
            $this->renderPayment();
            throw $exception;
        }

        $this->receiptUuid = $transaction->uuid;
        $this->resetValidation();
        $this->showCheckout = false;
        $this->cart = [];
        $this->tenders = [];
        $this->customerName = null;
        $this->customerPhone = null;
        $this->customerId = null;
        $this->customerSearch = '';
        $this->refreshCartQuote();
        $this->renderCart();
        $this->renderPayment();
        $this->triggerImplicitIslandRender('receipt');
        $this->triggerImplicitIslandRender('catalog');
        $this->triggerImplicitIslandRender('recent');
    }

    public function startNewSale(): void
    {
        $this->triggerImplicitIslandRender('receipt');
        $this->triggerImplicitIslandRender('cart-errors');
        $this->receiptUuid = null;
        $this->clientTransactionId = (string) Str::uuid7();
        $this->customerId = null;
        $this->customerName = null;
        $this->customerPhone = null;
        $this->customerSearch = '';
    }

    public function openReceipt(string $uuid): void
    {
        $this->triggerImplicitIslandRender('receipt');
        $this->triggerImplicitIslandRender('cart-errors');
        Validator::make(['uuid' => $uuid], ['uuid' => ['required', 'uuid']])->validate();
        $this->receiptUuid = strtolower($uuid);
    }

    #[Renderless]
    public function printReceipt(): void
    {
        abort_unless($this->receipt !== null, 404);
        $this->dispatch('pos-print-receipt');
    }

    private function renderPayment(): void
    {
        $this->triggerImplicitIslandRender('payment');
        $this->triggerImplicitIslandRender('cart-errors');
    }

    private function renderCart(): void
    {
        $this->triggerImplicitIslandRender('cart');
        $this->triggerImplicitIslandRender('cart-errors');
        if ($this->showCheckout) {
            $this->triggerImplicitIslandRender('payment');
        }
    }

    private function refreshCartQuote(): void
    {
        if ($this->locationId === null || ! app(PosAccess::class)->can($this->actor(), PosPermissions::CHECKOUT)) {
            return;
        }

        $this->cartQuote = app(PosTerminalCatalog::class)->quote($this->actor(), $this->locationId, $this->cart);
    }

    private function authorizeCheckout(): void
    {
        abort_unless($this->posSessionId !== null && $this->currentSession !== null, 403);
        app(PosAccess::class)->authorize($this->actor(), PosPermissions::CHECKOUT, $this->branchId());
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function branchId(): int
    {
        abort_unless($this->locationId !== null, 403);

        return $this->locationId;
    }
}
