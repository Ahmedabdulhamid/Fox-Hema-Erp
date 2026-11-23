<?php

namespace App\Modules\POS\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTransaction;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class LocalOfflineSaleRecovery
{
    public const SALE = '384660bf-4e9f-4581-b2fa-5fb647680a7b';

    public const ORPHAN = '032a31a9-42bf-40c3-b633-7a89eebedeb1';

    public const OUTBOX = 'a1895584-c7ef-4aa3-95ca-26db3aeb06f7';

    public const MANIFEST = ['032a31a9-42bf-40c3-b633-7a89eebedeb1', self::SALE, '7136240b-4aa3-427a-839f-0a87fe91a3e2'];

    public function enabled(): bool
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();

        return $connection->getDriverName() === 'mysql'
            && in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
            && ((app()->environment('local') && $database === 'fox_hema_pos' && (int) $connection->getConfig('port') === 3307)
                || (app()->environment('testing') && str_starts_with($database, 'codex_public_ids_test_')));
    }

    /** @param array<string, mixed> $evidence */
    public function cancel(User $reviewer, array $evidence, string $reason, bool $apply = false, ?string $rawEvidence = null): ?PosTransaction
    {
        return $this->dispose($reviewer, $evidence, $reason, self::SALE, $apply, $rawEvidence);
    }

    /** @param array<string, mixed> $evidence */
    public function archive(User $reviewer, array $evidence, string $reason, bool $apply = false, ?string $rawEvidence = null): ?PosTransaction
    {
        return $this->dispose($reviewer, $evidence, $reason, self::ORPHAN, $apply, $rawEvidence);
    }

    /** @param array<string, mixed> $evidence */
    private function dispose(User $reviewer, array $evidence, string $reason, string $transactionId, bool $apply, ?string $rawEvidence): ?PosTransaction
    {
        abort_unless(app()->runningInConsole() && $this->enabled(), 403, 'Recovery is restricted to the specified local test database.');
        abort_unless(mb_strlen(trim($reason)) >= 10 && mb_strlen($reason) <= 1000, 422, 'A documented cancellation reason is required.');
        if ($rawEvidence !== null) {
            abort_unless(json_decode($rawEvidence, true, flags: JSON_THROW_ON_ERROR) === $evidence, 422);
        }
        $scope = $evidence['scope'] ?? [];
        $manifest = $evidence['outbox']['transaction_ids'] ?? [];
        sort($manifest);
        abort_unless($manifest === self::MANIFEST && ($evidence['outbox']['id'] ?? null) === self::OUTBOX
            && ($evidence['outbox']['registered'] ?? false) === true && ($evidence['outbox']['frozen'] ?? false) === true
            && ($evidence['outbox']['sealed'] ?? true) === false, 422, 'The original frozen manifest is required.');
        if (app()->environment('local')) {
            abort_unless($scope === ['branch_id' => 2, 'device_id' => 1, 'session_id' => 6, 'cashier_id' => 1], 422);
        }
        $records = collect($evidence['transactions'] ?? []);
        $ids = $records->pluck('client_transaction_id')->sort()->values()->all();
        abort_unless($ids === self::MANIFEST, 422, 'All three complete transaction records are required.');
        foreach ($records as $record) {
            abort_unless(($record['scope'] ?? null) === $scope && ($record['scopeKey'] ?? null) === implode(':', $scope)
                && is_array($record['sale'] ?? null) && is_array($record['quote'] ?? null), 422);
        }
        $rejected = $records->firstWhere('client_transaction_id', $transactionId);
        $archival = $transactionId === self::ORPHAN;
        if ($archival) {
            abort_unless(($rejected['status'] ?? null) === 'completed'
                && isset($rejected['result']['sales_invoice_uuid'], $rejected['result']['invoice_number'], $rejected['synced_at'], $rejected['created_at']), 422,
                'The original successful historical acknowledgement is required.');
        } else {
            abort_unless(($rejected['status'] ?? null) === 'conflict' && isset($rejected['original_sale'], $rejected['error_history'], $rejected['created_at']), 422);
        }
        $hash = $this->hash($evidence);

        return DB::transaction(function () use ($reviewer, $scope, $evidence, $reason, $apply, $rawEvidence, $rejected, $hash, $records, $transactionId, $archival): ?PosTransaction {
            ModuleRegistryLock::acquire();
            $session = PosSession::whereKey($scope['session_id'])->lockForUpdate()->firstOrFail();
            abort_unless((int) $session->location_id === $scope['branch_id'] && (int) $session->pos_device_id === $scope['device_id']
                && (int) $session->opened_by === $scope['cashier_id'] && $session->status === 'open'
                && $session->closing_started_at === null && $session->closed_at === null, 409);
            app(PosAccess::class)->authorize($reviewer, PosPermissions::SHIFTS_APPROVE, $scope['branch_id']);
            $outbox = DB::table('pos_offline_outboxes')->where('uuid', self::OUTBOX)
                ->where('pos_session_id', $session->id)->lockForUpdate()->first();
            abort_unless($outbox !== null && $outbox->sealed_at === null, 409);
            abort_if($archival && $outbox->transaction_ids === null, 409, 'Archival requires the original frozen server manifest.');
            abort_unless($outbox->transaction_ids === null || json_decode($outbox->transaction_ids, true, flags: JSON_THROW_ON_ERROR) === self::MANIFEST, 409);
            $existing = PosTransaction::where('client_transaction_id', $transactionId)->lockForUpdate()->first();
            if ($existing !== null) {
                abort_unless($this->isTerminal($existing, self::OUTBOX)
                    && hash_equals($existing->result_payload['evidence_hash'], $hash)
                    && $existing->result_payload['reason'] === $reason, 409, 'Existing transaction differs; no recovery was applied.');

                return $existing;
            }
            $orderId = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'fox-hema:pos:'.$transactionId.':order');
            abort_if(DB::table('sales_orders')->where('uuid', $orderId)->exists(), 409, 'A source order already exists.');
            foreach ($rejected['sale']['tenders'] as $tender) {
                $paymentKey = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'fox-hema:pos:'.$transactionId.':payment-'.$tender['payment_method_id']);
                abort_if(DB::table('payments')->where('idempotency_key', $paymentKey)->exists(), 409, 'A payment already exists.');
            }
            if ($archival) {
                $acknowledgement = $rejected['result'];
                abort_if(DB::table('sales_orders')->where('id', $acknowledgement['sales_order_id'] ?? 0)->exists()
                    || DB::table('sales_invoices')->where('id', $acknowledgement['sales_invoice_id'] ?? 0)
                        ->orWhere('uuid', $acknowledgement['sales_invoice_uuid'])->exists(), 409,
                    'Historical identifiers have a current posting; manual review is required.');
                $cancelled = PosTransaction::where('client_transaction_id', self::SALE)->lockForUpdate()->first();
                abort_unless($cancelled !== null && $this->isTerminal($cancelled, self::OUTBOX)
                    && hash_equals($cancelled->result_payload['evidence_hash'], $hash), 409,
                    'The previously verified cancellation and original evidence must match.');
            }
            $posted = PosTransaction::where('client_transaction_id', self::MANIFEST[2])->lockForUpdate()->firstOrFail();
            abort_unless($posted->sync_status === 'completed' && (int) $posted->pos_session_id === (int) $session->id
                && (int) $posted->cashier_id === $scope['cashier_id'] && (int) $posted->pos_device_id === $scope['device_id']
                && (int) $posted->location_id === $scope['branch_id']
                && hash_equals($posted->payload_hash, app(PosCheckoutService::class)->payloadFingerprint($records->firstWhere('client_transaction_id', self::MANIFEST[2])['sale'])), 409);
            abort_unless($posted->salesInvoice?->status === 'posted', 409);
            if (! $apply) {
                return null;
            }
            $result = [$archival ? 'local_historical_orphan_archive' : 'local_test_cancellation' => true, 'outbox_id' => self::OUTBOX, 'scope' => $scope,
                'transaction_ids' => self::MANIFEST, 'evidence_hash' => $hash, 'reason' => $reason,
                'reviewer_id' => (int) $reviewer->id, $archival ? 'archived_at' : 'cancelled_at' => now()->toIso8601String()];
            if ($archival) {
                $result['disposition'] = 'historical_orphan_without_current_posting';
            }
            $payloadHash = app(PosCheckoutService::class)->payloadFingerprint($rejected['sale']);
            $result['signature'] = hash_hmac('sha256', $this->hash([$transactionId, $payloadHash, $result]), config('app.key'));
            $transaction = PosTransaction::create(['client_transaction_id' => $transactionId,
                'pos_session_id' => $session->id, 'pos_device_id' => $scope['device_id'], 'location_id' => $scope['branch_id'],
                'cashier_id' => $scope['cashier_id'], 'transaction_type' => 'sale', 'sync_status' => 'rejected',
                'error_code' => $archival ? 'local_historical_orphan_v1' : 'local_test_cancelled_v1', 'error_message' => $reason, 'payload_hash' => $payloadHash,
                'request_payload' => ['evidence' => $evidence, 'raw_evidence' => $rawEvidence], 'result_payload' => $result,
                'client_occurred_at' => $rejected['created_at'], 'server_received_at' => now(), 'processed_at' => now(), 'attempts' => 0]);
            if (! $archival) {
                DB::table('pos_offline_outboxes')->where('uuid', self::OUTBOX)->update(['transaction_ids' => json_encode(self::MANIFEST, JSON_THROW_ON_ERROR)]);
            }
            app(AuditLogger::class)->record($archival ? 'pos_local_historical_orphan_archived' : 'pos_local_test_sale_cancelled', metadata: [
                'client_transaction_id' => $transactionId, 'outbox_id' => self::OUTBOX, 'evidence_hash' => $hash,
                'reason' => $reason, 'source' => 'explicitly_authorized_local_cli',
            ], actor: $reviewer);

            return $transaction;
        }, 3);
    }

    public function isTerminal(PosTransaction $transaction, string $outboxId): bool
    {
        $archival = $transaction->client_transaction_id === self::ORPHAN;
        if (! $this->enabled() || ! in_array($transaction->client_transaction_id, [self::SALE, self::ORPHAN], true) || $outboxId !== self::OUTBOX
            || $transaction->sync_status !== 'rejected' || $transaction->error_code !== ($archival ? 'local_historical_orphan_v1' : 'local_test_cancelled_v1')
            || $transaction->sales_order_id !== null || $transaction->sales_invoice_id !== null) {
            return false;
        }
        $result = $transaction->result_payload ?? [];
        $signature = $result['signature'] ?? '';
        unset($result['signature']);
        $scope = ['branch_id' => (int) $transaction->location_id, 'device_id' => (int) $transaction->pos_device_id,
            'session_id' => (int) $transaction->pos_session_id, 'cashier_id' => (int) $transaction->cashier_id];

        return ($result[$archival ? 'local_historical_orphan_archive' : 'local_test_cancellation'] ?? false) === true && ($result['outbox_id'] ?? null) === $outboxId
            && ($result['scope'] ?? null) === $scope && ($result['transaction_ids'] ?? null) === self::MANIFEST
            && hash_equals($result['evidence_hash'] ?? '', $this->hash($transaction->request_payload['evidence'] ?? []))
            && hash_equals($signature, hash_hmac('sha256', $this->hash([$transaction->client_transaction_id, $transaction->payload_hash, $result]), config('app.key')));
    }

    private function hash(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($normalize, $item);
        };

        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR));
    }
}
