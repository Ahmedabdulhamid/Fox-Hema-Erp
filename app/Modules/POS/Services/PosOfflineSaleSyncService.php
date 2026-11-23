<?php

namespace App\Modules\POS\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\POS\Rules\WholeQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PosOfflineSaleSyncService
{
    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function synchronize(User $actor, PosDevice $device, array $envelope): array
    {
        Validator::make($envelope, [
            'acknowledgement_only' => ['sometimes', 'boolean'],
            'client_occurred_at' => ['sometimes', 'date'],
            'client_transaction_id' => ['required', 'uuid', 'lowercase'],
            'outbox_id' => ['required', 'uuid', 'lowercase'],
            'scope' => ['required', 'array:branch_id,device_id,session_id,cashier_id'],
            'scope.branch_id' => ['required', 'integer', 'min:1'],
            'scope.device_id' => ['required', 'integer', 'min:1'],
            'scope.session_id' => ['required', 'integer', 'min:1'],
            'scope.cashier_id' => ['required', 'integer', 'min:1'],
            'sale' => ['required', 'array:customer_id,customer_name,customer_phone,items,tenders,cash_received'],
            'sale.customer_id' => ['nullable', 'integer', 'min:1'],
            'sale.customer_name' => ['nullable', 'string', 'max:255'],
            'sale.customer_phone' => ['nullable', 'string', 'max:30'],
            'sale.items' => ['required', 'array', 'min:1', 'max:200'],
            'sale.items.*' => ['required', 'array:variant_id,quantity,discount_id'],
            'sale.items.*.variant_id' => ['required', 'integer', 'min:1', 'distinct'],
            'sale.items.*.quantity' => ['required', 'numeric', 'gt:0', new WholeQuantity],
            'sale.items.*.discount_id' => ['nullable', 'integer', 'min:1'],
            'sale.tenders' => ['required', 'array', 'min:1', 'max:10'],
            'sale.tenders.*' => ['required', 'array:payment_method_id,amount,external_reference'],
            'sale.tenders.*.payment_method_id' => ['required', 'integer', 'min:1', 'distinct'],
            'sale.tenders.*.amount' => ['required', 'numeric', 'gt:0'],
            'sale.tenders.*.external_reference' => ['nullable', 'string', 'max:191'],
            'sale.cash_received' => ['nullable', 'numeric', 'gte:0'],
            'quote' => ['required', 'array:items,subtotal,discount_amount,tax_amount,total_amount'],
            'quote.items' => ['required', 'array', 'list', 'min:1', 'max:200'],
            'quote.items.*' => ['required', 'array:variant_id,quantity,discount_id,unit_price,subtotal,discount_amount,tax_amount,line_total'],
            'quote.items.*.variant_id' => ['required', 'integer', 'min:1', 'distinct'],
            'quote.items.*.quantity' => ['required', 'numeric', 'gt:0', new WholeQuantity],
            'quote.items.*.discount_id' => ['nullable', 'integer', 'min:1'],
            'quote.items.*.unit_price' => ['required', 'numeric', 'gte:0'],
            'quote.items.*.subtotal' => ['required', 'numeric', 'gte:0'],
            'quote.items.*.discount_amount' => ['required', 'numeric', 'gte:0'],
            'quote.items.*.tax_amount' => ['required', 'numeric', 'gte:0'],
            'quote.items.*.line_total' => ['required', 'numeric', 'gte:0'],
            'quote.subtotal' => ['required', 'numeric', 'gte:0'],
            'quote.discount_amount' => ['required', 'numeric', 'gte:0'],
            'quote.tax_amount' => ['required', 'numeric', 'gte:0'],
            'quote.total_amount' => ['required', 'numeric', 'gte:0'],
        ])->validate();

        $scope = $envelope['scope'];
        abort_unless((int) $scope['device_id'] === (int) $device->id
            && (int) $scope['cashier_id'] === (int) $actor->id, 403);

        $session = PosSession::query()
            ->whereKey($scope['session_id'])
            ->where('pos_device_id', $device->id)
            ->where('location_id', $scope['branch_id'])
            ->where('opened_by', $actor->id)
            ->firstOrFail();
        abort_unless((int) $device->location_id === (int) $session->location_id
            && $device->is_active && $device->revoked_at === null, 403);

        $transaction = DB::transaction(function () use ($actor, $session, $envelope): PosTransaction {
            ModuleRegistryLock::acquire();
            PosSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            app(PosOfflineOutboxes::class)->authorizeSynchronization(
                (int) $session->id, $envelope['outbox_id'],
            );

            app(PosAccess::class)->authorize($actor, PosPermissions::CHECKOUT, (int) $session->location_id);
            $existing = PosTransaction::where('client_transaction_id', $envelope['client_transaction_id'])->lockForUpdate()->first();
            if ($existing !== null && app(LocalOfflineSaleRecovery::class)->isTerminal($existing, $envelope['outbox_id'])) {
                abort_unless((int) $existing->pos_session_id === (int) $session->id
                    && hash_equals($existing->payload_hash, app(PosCheckoutService::class)->payloadFingerprint($envelope['sale'])), 409);

                return $existing;
            }
            abort_if($envelope['acknowledgement_only'] ?? false, 409, 'No verified terminal cancellation exists; the saved evidence must be reviewed.');
            $transaction = app(PosCheckoutService::class)->checkout(
                $actor, (int) $session->id, $envelope['client_transaction_id'], $envelope['sale'], $envelope['quote'], $envelope['outbox_id'], $envelope['client_occurred_at'] ?? null,
            );
            abort_unless((int) $transaction->pos_session_id === (int) $session->id, 403);

            return $transaction;
        }, 3);

        return [
            'client_transaction_id' => $transaction->client_transaction_id,
            'status' => app(LocalOfflineSaleRecovery::class)->isTerminal($transaction, $envelope['outbox_id'])
                ? (($transaction->result_payload['local_historical_orphan_archive'] ?? false) ? 'archived' : 'cancelled') : $transaction->sync_status,
            'transaction_uuid' => $transaction->uuid,
            'result' => $transaction->result_payload,
        ];
    }
}
