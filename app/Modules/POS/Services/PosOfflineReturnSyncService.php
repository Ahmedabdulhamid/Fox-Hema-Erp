<?php

namespace App\Modules\POS\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\POS\Rules\WholeQuantity;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use App\Modules\Sales\Models\SalesInvoiceDeliveryMatch;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesReturnService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PosOfflineReturnSyncService
{
    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function synchronize(User $actor, PosDevice $device, array $envelope): array
    {
        $validated = Validator::make($envelope, [
            'client_transaction_id' => ['required', 'uuid', 'lowercase'],
            'source_client_transaction_id' => ['required', 'uuid', 'lowercase'],
            'client_occurred_at' => ['required', 'date'],
            'authorization_token' => ['required', 'string', 'max:12000'],
            'scope' => ['required', 'array:branch_id,device_id,session_id,cashier_id'],
            'scope.branch_id' => ['required', 'integer', 'min:1'],
            'scope.device_id' => ['required', 'integer', 'min:1'],
            'scope.session_id' => ['required', 'integer', 'min:1'],
            'scope.cashier_id' => ['required', 'integer', 'min:1'],
            'return' => ['required', 'array:return_date,reason,notes,items'],
            'return.return_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'return.reason' => ['nullable', 'string', 'max:255'],
            'return.notes' => ['nullable', 'string', 'max:16000'],
            'return.items' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'return.items.*' => ['required', 'array:sales_delivery_item_id,sales_invoice_item_id,quantity,disposition,notes'],
            'return.items.*.sales_delivery_item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'return.items.*.sales_invoice_item_id' => ['required', 'integer', 'min:1'],
            'return.items.*.quantity' => ['required', 'numeric', 'gt:0', new WholeQuantity],
            'return.items.*.disposition' => ['required', 'in:restock,damaged,scrap'],
            'return.items.*.notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        $scope = array_map('intval', $validated['scope']);
        abort_unless($scope['device_id'] === (int) $device->id
            && $scope['cashier_id'] === (int) $actor->id, 403);
        $validated['return']['items'] = array_map(fn (array $item): array => [
            'sales_delivery_item_id' => (int) $item['sales_delivery_item_id'],
            'sales_invoice_item_id' => (int) $item['sales_invoice_item_id'],
            'quantity' => app(PurchaseOrderCalculator::class)->decimal($item['quantity'], 'quantity', 18, 4),
            'disposition' => $item['disposition'],
            'notes' => $item['notes'] ?? null,
        ], $validated['return']['items']);
        usort($validated['return']['items'], fn (array $left, array $right): int => $left['sales_delivery_item_id'] <=> $right['sales_delivery_item_id']);

        $clientTransactionId = strtolower($validated['client_transaction_id']);
        $sourceClientTransactionId = strtolower($validated['source_client_transaction_id']);
        $payload = [
            'source_client_transaction_id' => $sourceClientTransactionId,
            'return' => $validated['return'],
        ];
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(fn (): array => RequestAuthorizationFacts::duringLockedTransaction(
                fn (): array => $this->process($actor, $device, $scope, $validated, $payload, $payloadHash, $clientTransactionId, $sourceClientTransactionId),
            ), 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            return DB::transaction(fn (): array => RequestAuthorizationFacts::duringLockedTransaction(
                fn (): array => $this->replay(
                    $actor,
                    $device,
                    $scope,
                    $validated['authorization_token'],
                    $clientTransactionId,
                    $sourceClientTransactionId,
                    $payloadHash,
                ),
            ), 3);
        }
    }

    /**
     * @param  array<string, int>  $scope
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function process(
        User $actor,
        PosDevice $device,
        array $scope,
        array $validated,
        array $payload,
        string $payloadHash,
        string $clientTransactionId,
        string $sourceClientTransactionId,
    ): array {
        app(PosOfflineAuthorizationLease::class)->authorize($actor, $scope, $validated['authorization_token']);
        $session = PosSession::query()->whereKey($scope['session_id'])->lockForUpdate()->firstOrFail();
        $storedDevice = PosDevice::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
        abort_unless((int) $session->pos_device_id === (int) $storedDevice->id
            && (int) $session->location_id === $scope['branch_id']
            && (int) $session->opened_by === (int) $actor->id
            && (int) $storedDevice->location_id === $scope['branch_id']
            && $storedDevice->is_active && $storedDevice->revoked_at === null
            && $session->status === 'open' && $session->closing_started_at === null && $session->closed_at === null, 403);

        $source = PosTransaction::query()
            ->where('client_transaction_id', $sourceClientTransactionId)
            ->lockForUpdate()
            ->firstOrFail();
        abort_unless($source->transaction_type === 'sale' && $source->sync_status === 'completed'
            && (int) $source->cashier_id === (int) $actor->id
            && (int) $source->pos_device_id === (int) $storedDevice->id
            && (int) $source->pos_session_id === (int) $session->id
            && (int) $source->location_id === (int) $session->location_id
            && $source->sales_order_id !== null && $source->sales_invoice_id !== null, 409);

        $invoiceItemIds = array_column($payload['return']['items'], 'sales_invoice_item_id');
        $deliveryItemIds = array_column($payload['return']['items'], 'sales_delivery_item_id');
        $matches = SalesInvoiceDeliveryMatch::query()
            ->whereIn('sales_invoice_item_id', $invoiceItemIds)
            ->whereIn('sales_delivery_item_id', $deliveryItemIds)
            ->whereHas('salesInvoiceItem', fn ($query) => $query->where('sales_invoice_id', $source->sales_invoice_id))
            ->get(['sales_invoice_item_id', 'sales_delivery_item_id'])
            ->map(fn (SalesInvoiceDeliveryMatch $match): string => $match->sales_delivery_item_id.':'.$match->sales_invoice_item_id)
            ->all();

        foreach ($payload['return']['items'] as $item) {
            abort_unless(in_array($item['sales_delivery_item_id'].':'.$item['sales_invoice_item_id'], $matches, true), 409,
                'The return line does not belong to the original POS invoice.');
        }

        $existing = PosTransaction::query()
            ->where('client_transaction_id', $clientTransactionId)
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            return $this->assertReplay($actor, $existing, $payloadHash, (int) $source->id);
        }

        $returnData = $payload['return'] + [
            'sales_order_id' => (int) $source->sales_order_id,
            'sales_invoice_id' => (int) $source->sales_invoice_id,
        ];
        $return = app(SalesReturnService::class)->create($actor, $returnData, $clientTransactionId);
        $transaction = PosTransaction::query()->create([
            'client_transaction_id' => $clientTransactionId,
            'source_pos_transaction_id' => $source->id,
            'pos_device_id' => $storedDevice->id,
            'pos_session_id' => $session->id,
            'location_id' => $session->location_id,
            'cashier_id' => $actor->id,
            'sales_return_id' => $return->id,
            'transaction_type' => 'return',
            'sync_status' => 'completed',
            'client_occurred_at' => $validated['client_occurred_at'],
            'server_received_at' => now(),
            'processed_at' => now(),
            'payload_hash' => $payloadHash,
            'request_payload' => $payload,
            'result_payload' => [
                'return_uuid' => $return->uuid,
                'return_number' => $return->return_number,
                'return_status' => $return->status,
                'refund_status' => 'not_started',
                'inventory_status' => 'not_posted',
            ],
            'attempts' => 1,
            'last_attempt_at' => now(),
        ]);

        return $this->acknowledgement($transaction);
    }

    /**
     * @param  array<string, int>  $scope
     * @return array<string, mixed>
     */
    private function replay(
        User $actor,
        PosDevice $device,
        array $scope,
        string $authorizationToken,
        string $clientTransactionId,
        string $sourceClientTransactionId,
        string $payloadHash,
    ): array {
        app(PosOfflineAuthorizationLease::class)->authorize($actor, $scope, $authorizationToken);
        $transaction = PosTransaction::query()->where('client_transaction_id', $clientTransactionId)->firstOrFail();
        $source = PosTransaction::query()->where('client_transaction_id', $sourceClientTransactionId)->firstOrFail();
        abort_unless((int) $source->pos_device_id === (int) $device->id
            && (int) $source->pos_session_id === $scope['session_id']
            && (int) $source->location_id === $scope['branch_id']
            && (int) $source->cashier_id === (int) $actor->id, 403);

        return $this->assertReplay($actor, $transaction, $payloadHash, (int) $source->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertReplay(User $actor, PosTransaction $transaction, string $payloadHash, int $sourceTransactionId): array
    {
        if ($transaction->transaction_type !== 'return'
            || $transaction->sync_status !== 'completed'
            || (int) $transaction->cashier_id !== (int) $actor->id
            || (int) $transaction->source_pos_transaction_id !== $sourceTransactionId
            || $transaction->sales_return_id === null
            || ! hash_equals((string) $transaction->payload_hash, $payloadHash)) {
            throw ValidationException::withMessages([
                'client_transaction_id' => __('pos.validation.transaction_key_conflict'),
            ]);
        }

        $return = SalesReturn::query()->whereKey($transaction->sales_return_id)->firstOrFail();
        abort_unless((int) $return->created_by === (int) $actor->id
            && (int) $return->location_id === (int) $transaction->location_id, 403);

        return $this->acknowledgement($transaction);
    }

    /**
     * @return array<string, mixed>
     */
    private function acknowledgement(PosTransaction $transaction): array
    {
        return [
            'client_transaction_id' => $transaction->client_transaction_id,
            'status' => $transaction->sync_status,
            'transaction_uuid' => $transaction->uuid,
            'result' => $transaction->result_payload,
        ];
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            && (str_contains(strtolower($exception->getMessage()), 'unique')
                || str_contains(strtolower($exception->getMessage()), 'duplicate'));
    }
}
