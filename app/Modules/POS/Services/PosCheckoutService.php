<?php

namespace App\Modules\POS\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Payments\Services\CustomerAccounts;
use App\Modules\Payments\Services\CustomerFinancials;
use App\Modules\Payments\Services\CustomerPayments;
use App\Modules\Payments\Services\PaymentMoney;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\POS\Data\PosContext;
use App\Modules\POS\Models\PosDevicePaymentMethod;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\POS\Rules\WholeQuantity;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class PosCheckoutService
{
    private static ?int $lateSessionId = null;

    public static function isPostingLate(int $sessionId): bool
    {
        return self::$lateSessionId === $sessionId;
    }

    /**
     * @param  array{customer_id?: int|string|null, customer_name?: ?string, customer_phone?: ?string, items: list<array{variant_id: int|string, quantity: int|float|string, discount_id?: int|string|null}>, tenders: list<array{payment_method_id: int|string, amount: int|float|string, external_reference?: ?string}>, cash_received?: int|float|string|null}  $data
     * @param  array{items: list<array<string, mixed>>, subtotal: string, discount_amount: string, tax_amount: string, total_amount: string}|null  $expectedQuote
     */
    public function checkout(User $actor, int $sessionId, string $clientTransactionId, array $data, ?array $expectedQuote = null, ?string $offlineOutboxId = null, ?string $clientOccurredAt = null): PosTransaction
    {
        return $this->runCheckout($actor, $sessionId, $clientTransactionId, $data, $expectedQuote, $offlineOutboxId, $clientOccurredAt);
    }

    /** @param array<string, mixed> $data
     * @param  array<string, mixed>|null  $expectedQuote
     */
    private function runCheckout(User $actor, int $sessionId, string $clientTransactionId, array $data,
        ?array $expectedQuote = null, ?string $offlineOutboxId = null, ?string $clientOccurredAt = null): PosTransaction
    {
        Validator::make(['client_transaction_id' => $clientTransactionId], [
            'client_transaction_id' => ['required', 'uuid'],
        ])->validate();
        $clientTransactionId = strtolower($clientTransactionId);
        $payload = $this->normalizePayload($data);
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $previousLateSession = self::$lateSessionId;
        try {
            return DB::transaction(function () use ($actor, $sessionId, $clientTransactionId, $payload, $payloadHash, $expectedQuote, $offlineOutboxId, $clientOccurredAt): PosTransaction {
                return RequestAuthorizationFacts::duringLockedTransaction(function () use ($actor, $sessionId, $clientTransactionId, $payload, $payloadHash, $expectedQuote, $offlineOutboxId, $clientOccurredAt): PosTransaction {
                    $existing = PosTransaction::query()
                        ->where('client_transaction_id', $clientTransactionId)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        $boundOutbox = $existing->request_payload['offline_outbox_id'] ?? null;
                        if ($offlineOutboxId !== null && $boundOutbox !== null && $offlineOutboxId !== $boundOutbox) {
                            throw ValidationException::withMessages(['outbox' => __('pos.validation.offline_outbox_changed')]);
                        }

                        return $this->replay($actor, $existing, $payloadHash);
                    }

                    $session = PosSession::query()->whereKey($sessionId)->lockForUpdate()->firstOrFail();
                    app(PosAccess::class)->authorize($actor, PosPermissions::CHECKOUT, (int) $session->location_id);
                    $late = $offlineOutboxId !== null && $session->status === 'closed';
                    if ($late) {
                        app(PosOfflineOutboxes::class)->authorizeLateSale($actor, $session, $offlineOutboxId, $clientOccurredAt);
                        self::$lateSessionId = (int) $session->id;
                    }
                    if (! $late && ($session->status !== 'open' || $session->closing_started_at !== null || $session->closed_at !== null)) {
                        throw ValidationException::withMessages(['session' => __('pos.validation.session_not_open')]);
                    }
                    if (count($payload['tenders']) !== 1) {
                        throw ValidationException::withMessages([
                            'tenders' => __('pos.validation.single_payment_method'),
                        ]);
                    }
                    if ($payload['customer_id'] !== null) {
                        abort_unless(app(CustomerAccess::class)
                            ->allows($actor, PaymentsPermissions::ALLOCATE, (int) $session->location_id), 403);
                        $customer = Customer::query()
                            ->whereKey($payload['customer_id'])
                            ->where('location_id', $session->location_id)
                            ->where('is_active', true)
                            ->lockForUpdate()
                            ->firstOrFail();
                        $orderPayload = $payload;
                        $orderPayload['customer_name'] = $customer->name;
                        $orderPayload['customer_phone'] = $customer->phone;
                    } else {
                        $orderPayload = $payload;
                    }
                    $attributes = [
                        'schema_version' => 1,
                        'client_transaction_id' => $clientTransactionId,
                        'pos_device_id' => $session->pos_device_id,
                        'pos_session_id' => $session->id,
                        'location_id' => $session->location_id,
                        'cashier_id' => $actor->id,
                        'transaction_type' => 'sale',
                        'sync_status' => 'processing',
                        'client_occurred_at' => $clientOccurredAt ?? now(),
                        'server_received_at' => now(),
                        'payload_hash' => $payloadHash,
                        'request_payload' => $payload + ($offlineOutboxId === null ? [] : ['offline_outbox_id' => $offlineOutboxId]),
                        'attempts' => 1,
                        'last_attempt_at' => now(),
                    ];
                    $transaction = PosTransaction::query()->create($attributes);
                    $context = PosContext::fromTransaction($actor, $transaction);
                    $cart = collect($payload['items'])->mapWithKeys(fn (array $item): array => [
                        $item['variant_id'] => [
                            'quantity' => $item['quantity'],
                            'discount_id' => $item['discount_id'],
                        ],
                    ])->all();
                    $quote = app(PosTerminalCatalog::class)->quote($actor, $context->locationId, $cart);
                    if ($expectedQuote !== null && ! $this->quoteMatches($expectedQuote, $quote)) {
                        throw ValidationException::withMessages(['cart' => __('pos.validation.price_changed')]);
                    }
                    $order = $this->createAndConfirmOrder($actor, $context, $orderPayload);
                    foreach (['subtotal', 'discount_amount', 'tax_amount', 'total_amount'] as $field) {
                        if (! BigDecimal::of($order->{$field})->isEqualTo($quote[$field])) {
                            throw ValidationException::withMessages([
                                'cart' => __('pos.validation.price_changed'),
                            ]);
                        }
                    }
                    $delivery = $this->deliverOrder($actor, $order);
                    $invoice = $this->invoiceDelivery($actor, $order, $delivery);
                    if ($payload['customer_id'] !== null) {
                        app(CustomerAccounts::class)->linkInvoice(
                            $actor,
                            Customer::query()->whereKey($payload['customer_id'])->firstOrFail(),
                            $invoice,
                            [
                                'settlement_terms' => 'cash',
                                'planned_immediate_amount' => (string) $invoice->total_amount,
                            ],
                            $this->derivedUuid($clientTransactionId, 'customer-link'),
                        );
                    }
                    $tender = $this->collectTender($actor, $context, $invoice, $payload);
                    $settlement = app(CustomerFinancials::class)->invoice($actor, $invoice, locking: true);

                    if ($settlement['payment_status'] !== 'settled'
                        || ! BigDecimal::of($settlement['outstanding'])->isZero()
                        || ! BigDecimal::of($settlement['excess_allocation'])->isZero()) {
                        throw ValidationException::withMessages([
                            'payment' => __('pos.validation.invoice_not_settled'),
                        ]);
                    }

                    $change = BigDecimal::of($payload['cash_received'])->minus($tender['cash_applied'])->toScale(4);
                    $result = [
                        'sales_order_id' => (int) $order->id,
                        'sales_order_number' => $order->order_number,
                        'sales_invoice_id' => (int) $invoice->id,
                        'sales_invoice_uuid' => $invoice->uuid,
                        'invoice_number' => $invoice->invoice_number,
                        'subtotal' => $invoice->subtotal,
                        'discount_amount' => $invoice->discount_amount,
                        'tax_amount' => $invoice->tax_amount,
                        'total_amount' => $invoice->total_amount,
                        'paid_amount' => $settlement['paid'],
                        'cash_change' => (string) $change,
                        'payments' => $tender['payments'],
                        'return_lines' => app(PosOfflineReturnSources::class)->forInvoices(collect([$invoice]))[$invoice->id] ?? [],
                    ];
                    $transaction->fill([
                        'sales_order_id' => $order->id,
                        'sales_invoice_id' => $invoice->id,
                        'sync_status' => 'completed',
                        'processed_at' => now(),
                        'result_payload' => $result,
                        'error_code' => null,
                        'error_message' => null,
                    ])->save();
                    if ($late) {
                        $result['late_synchronization'] = true;
                        $transaction->update(['result_payload' => $result]);
                        app(AuditLogger::class)->record('pos_sale_synchronized_after_closure', metadata: [
                            'pos_session_id' => $session->id, 'client_transaction_id' => $clientTransactionId,
                            'reconciliation' => app(PosShiftService::class)->lateReconciliation($actor, (int) $session->id),
                        ], actor: $actor);
                    }

                    return $transaction->refresh();
                });
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = PosTransaction::query()
                ->where('client_transaction_id', $clientTransactionId)
                ->first();
            if ($existing === null) {
                throw $exception;
            }
            $boundOutbox = $existing->request_payload['offline_outbox_id'] ?? null;
            if ($offlineOutboxId !== null && $boundOutbox !== null && $offlineOutboxId !== $boundOutbox) {
                throw ValidationException::withMessages(['outbox' => __('pos.validation.offline_outbox_changed')]);
            }

            return DB::transaction(fn (): PosTransaction => $this->replay($actor, $existing, $payloadHash), 3);
        } finally {
            self::$lateSessionId = $previousLateSession;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{customer_id: int|null, customer_name: ?string, customer_phone: ?string, items: list<array{variant_id: int, quantity: string, discount_id: int|null}>, tenders: list<array{payment_method_id: int, amount: string, external_reference: ?string}>, cash_received: string}
     */
    private function normalizePayload(array $data): array
    {
        if (array_diff(array_keys($data), ['customer_id', 'customer_name', 'customer_phone', 'items', 'tenders', 'cash_received']) !== []) {
            throw ValidationException::withMessages(['checkout' => __('pos.validation.invalid_checkout')]);
        }
        Validator::make($data, [
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*' => ['required', 'array:variant_id,quantity,discount_id'],
            'items.*.variant_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', new WholeQuantity],
            'items.*.discount_id' => ['nullable', 'integer', 'min:1'],
            'tenders' => ['required', 'array', 'min:1', 'max:10'],
            'tenders.*' => ['required', 'array:payment_method_id,amount,external_reference'],
            'tenders.*.payment_method_id' => ['required', 'integer', 'min:1', 'distinct'],
            'tenders.*.amount' => ['required', 'numeric', 'gt:0'],
            'tenders.*.external_reference' => ['nullable', 'string', 'max:191'],
            'cash_received' => ['nullable', 'numeric', 'gte:0'],
        ])->validate();

        $items = [];
        foreach ($data['items'] as $item) {
            $items[] = [
                'variant_id' => (int) $item['variant_id'],
                'quantity' => app(PurchaseOrderCalculator::class)
                    ->decimal($item['quantity'], 'items.quantity', 18, 4),
                'discount_id' => isset($item['discount_id']) ? (int) $item['discount_id'] : null,
            ];
        }
        usort($items, fn (array $left, array $right): int => $left['variant_id'] <=> $right['variant_id']);

        $tenders = [];
        foreach ($data['tenders'] as $tender) {
            $tenders[] = [
                'payment_method_id' => (int) $tender['payment_method_id'],
                'amount' => app(PaymentMoney::class)->normalize($tender['amount'], 'EGP'),
                'external_reference' => filled($tender['external_reference'] ?? null)
                    ? trim((string) $tender['external_reference'])
                    : null,
            ];
        }
        usort($tenders, fn (array $left, array $right): int => $left['payment_method_id'] <=> $right['payment_method_id']);

        $cashReceivedInput = $data['cash_received'] ?? '0';
        $cashReceived = (is_string($cashReceivedInput) || is_int($cashReceivedInput))
            && preg_match('/^0+(\.0{1,4})?$/D', (string) $cashReceivedInput)
                ? '0.0000'
                : app(PaymentMoney::class)->normalize($cashReceivedInput, 'EGP');

        $customerId = isset($data['customer_id']) ? (int) $data['customer_id'] : null;

        return [
            'customer_id' => $customerId,
            'customer_name' => $customerId === null && filled($data['customer_name'] ?? null) ? trim((string) $data['customer_name']) : null,
            'customer_phone' => $customerId === null && filled($data['customer_phone'] ?? null) ? trim((string) $data['customer_phone']) : null,
            'items' => $items,
            'tenders' => $tenders,
            'cash_received' => $cashReceived,
        ];
    }

    /**
     * Verify persisted JSON with the same canonical field ordering used by checkout.
     *
     * @param  array<string, mixed>  $data
     */
    public function payloadFingerprint(array $data): string
    {
        return hash('sha256', json_encode($this->normalizePayload($data), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createAndConfirmOrder(User $actor, PosContext $context, array $payload): SalesOrder
    {
        $productIds = ProductVariant::query()
            ->whereKey(array_column($payload['items'], 'variant_id'))
            ->orderBy('id')
            ->pluck('product_id', 'id');
        $order = app(SalesOrderService::class)->createForPos($actor, [
            'location_id' => $context->locationId,
            'customer_name' => $payload['customer_name'],
            'customer_phone' => $payload['customer_phone'],
            'ordered_at' => now()->format('Y-m-d H:i:s'),
            'items' => array_map(fn (array $item): array => [
                'product_id' => (int) $productIds[$item['variant_id']],
                'variant_id' => $item['variant_id'],
                'quantity' => $item['quantity'],
                'discount_id' => $item['discount_id'],
            ], $payload['items']),
        ], $context);

        return app(SalesOrderConfirmationService::class)->confirm(
            $actor,
            $order,
            app(SalesOrderService::class)->revision($order),
        );
    }

    private function deliverOrder(User $actor, SalesOrder $order): SalesDelivery
    {
        $order->loadMissing('items');
        $delivery = app(SalesDeliveryService::class)->create($actor, [
            'sales_order_id' => $order->id,
            'delivered_at' => now()->format('Y-m-d H:i:s'),
            'items' => $order->items->map(fn ($item): array => [
                'sales_order_item_id' => (int) $item->id,
                'quantity' => (string) $item->quantity,
            ])->all(),
        ], $this->derivedUuid((string) $order->uuid, 'delivery'));

        return app(SalesDeliveryPostingService::class)->post(
            $actor,
            $delivery,
            app(SalesDeliveryService::class)->revision($delivery),
        );
    }

    private function invoiceDelivery(User $actor, SalesOrder $order, SalesDelivery $delivery): SalesInvoice
    {
        $delivery->loadMissing('items');
        $invoice = app(SalesInvoiceService::class)->create($actor, [
            'sales_order_id' => $order->id,
            'invoice_date' => today()->toDateString(),
            'items' => $delivery->items->map(fn ($item): array => [
                'sales_delivery_item_id' => (int) $item->id,
                'quantity' => (string) $item->quantity,
            ])->all(),
        ], $this->derivedUuid((string) $order->uuid, 'invoice'));

        return app(SalesInvoiceService::class)->post(
            $actor,
            $invoice,
            app(SalesInvoiceService::class)->revision($invoice),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{payments: list<array{payment_method_id: int, payment_method: string, amount: string, reference: ?string}>, cash_applied: string}
     */
    private function collectTender(User $actor, PosContext $context, SalesInvoice $invoice, array $payload): array
    {
        $methods = PaymentMethod::query()
            ->whereKey(array_column($payload['tenders'], 'payment_method_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        if ($methods->count() !== count($payload['tenders'])) {
            throw ValidationException::withMessages(['tenders' => __('pos.validation.payment_method_unavailable')]);
        }

        $configurations = PosDevicePaymentMethod::query()
            ->where('pos_device_id', $context->deviceId)
            ->whereIn('payment_method_id', array_column($payload['tenders'], 'payment_method_id'))
            ->where('is_active', true)
            ->orderBy('payment_method_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('payment_method_id');
        $tenderTotal = BigDecimal::zero();
        $cashApplied = BigDecimal::zero();
        foreach ($payload['tenders'] as $tender) {
            $method = $methods->get($tender['payment_method_id']);
            $configuration = $configurations->get($tender['payment_method_id']);
            if ($method === null || $configuration === null || ! $method->is_active || ! $method->available_in_pos
                || ! in_array($method->type, PaymentMethod::TYPES, true)) {
                throw ValidationException::withMessages(['tenders' => __('pos.validation.payment_method_unavailable')]);
            }
            app(PosAccess::class)->tender(
                $actor,
                $context,
                (int) $method->id,
                $method->type === 'cash' ? (int) $configuration->treasury_account_id : null,
            );
            $tenderTotal = $tenderTotal->plus($tender['amount']);
            if ($method->type === 'cash') {
                $cashApplied = $cashApplied->plus($tender['amount']);
            }
        }

        if (BigDecimal::of($payload['cash_received'])->isLessThan($cashApplied)) {
            throw ValidationException::withMessages(['cash_received' => __('pos.validation.cash_received_short')]);
        }
        if (! $tenderTotal->isEqualTo($invoice->total_amount)
            || ($cashApplied->isZero() && ! BigDecimal::of($payload['cash_received'])->isZero())) {
            throw ValidationException::withMessages(['tenders' => __('pos.validation.tender_total_mismatch')]);
        }

        $payments = [];
        foreach ($payload['tenders'] as $tender) {
            $method = $methods->get($tender['payment_method_id']);
            $configuration = $configurations->get($tender['payment_method_id']);
            $payment = app(CustomerPayments::class)->payInvoice($actor, $invoice, [
                'payment_method_id' => (int) $method->id,
                'treasury_account_id' => $method->type === 'cash' ? (int) $configuration->treasury_account_id : null,
                'amount' => $tender['amount'],
                'payment_date' => today()->toDateString(),
                'external_reference' => $tender['external_reference'],
                'notes' => 'POS sale '.$invoice->invoice_number,
            ], $this->derivedUuid((string) $context->clientTransactionId, 'payment-'.$method->id), $context);
            $payments[] = [
                'payment_method_id' => (int) $method->id,
                'payment_method' => $method->name,
                'amount' => (string) $payment->amount,
                'reference' => $payment->external_reference,
            ];
        }

        return [
            'payments' => $payments,
            'cash_applied' => (string) $cashApplied,
        ];
    }

    private function replay(User $actor, PosTransaction $transaction, string $payloadHash): PosTransaction
    {
        if ($transaction->transaction_type !== 'sale'
            || (int) $transaction->cashier_id !== (int) $actor->id
            || ! hash_equals((string) $transaction->payload_hash, $payloadHash)
            || $transaction->sync_status !== 'completed'
            || $transaction->sales_order_id === null
            || $transaction->sales_invoice_id === null) {
            throw ValidationException::withMessages([
                'client_transaction_id' => __('pos.validation.transaction_key_conflict'),
            ]);
        }

        $context = PosContext::fromTransactionForPayment($actor, $transaction, historical: true);
        $invoice = SalesInvoice::query()->whereKey($transaction->sales_invoice_id)->firstOrFail();
        app(PosAccess::class)->invoice($actor, $context, $invoice, historical: true);
        $settlement = app(CustomerFinancials::class)->invoice($actor, $invoice);
        if ($settlement['payment_status'] !== 'settled' || ! BigDecimal::of($settlement['outstanding'])->isZero()) {
            throw ValidationException::withMessages(['payment' => __('pos.validation.invoice_not_settled')]);
        }

        return $transaction;
    }

    /**
     * Compare only commercial facts used to calculate the offline amount; display metadata is not authoritative.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function quoteMatches(array $expected, array $actual): bool
    {
        foreach (['subtotal', 'discount_amount', 'tax_amount', 'total_amount'] as $field) {
            if (! $this->decimalValuesMatch($expected[$field] ?? null, $actual[$field] ?? null)) {
                return false;
            }
        }

        $expectedItems = collect($expected['items'] ?? [])->keyBy('variant_id');
        $actualItems = collect($actual['items'] ?? [])->keyBy('variant_id');
        if ($expectedItems->count() !== $actualItems->count()) {
            return false;
        }
        foreach ($actualItems as $variantId => $item) {
            $candidate = $expectedItems->get($variantId);
            if (! is_array($candidate) || (int) ($candidate['discount_id'] ?? 0) !== (int) ($item['discount_id'] ?? 0)) {
                return false;
            }
            foreach (['quantity', 'unit_price', 'subtotal', 'discount_amount', 'tax_amount', 'line_total'] as $field) {
                if (! $this->decimalValuesMatch($candidate[$field] ?? null, $item[$field] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function decimalValuesMatch(mixed $left, mixed $right): bool
    {
        if ((! is_string($left) && ! is_int($left) && ! is_float($left))
            || (! is_string($right) && ! is_int($right) && ! is_float($right))) {
            return false;
        }

        try {
            return BigDecimal::of($left)->isEqualTo($right);
        } catch (MathException) {
            return false;
        }
    }

    private function derivedUuid(string $namespace, string $action): string
    {
        return strtolower((string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'fox-hema:pos:'.$namespace.':'.$action));
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            && (str_contains(strtolower($exception->getMessage()), 'unique')
                || str_contains(strtolower($exception->getMessage()), 'duplicate'));
    }
}
