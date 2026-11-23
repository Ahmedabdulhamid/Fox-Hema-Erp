<?php

namespace App\Modules\POS\Data;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\POS\Services\PosAccess;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final readonly class PosContext
{
    private function __construct(public int $transactionId, public int $sessionId, public int $deviceId, public int $locationId, public int $cashierId, public string $clientTransactionId) {}

    public static function fromTransaction(User $actor, PosTransaction $transaction): self
    {
        return self::fromTransactionForPayment($actor, $transaction);
    }

    public static function fromTransactionForPayment(User $actor, PosTransaction $transaction, bool $historical = false): self
    {
        self::requireTransaction();
        ModuleRegistryLock::acquire();
        $transaction = PosTransaction::whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();
        $context = new self((int) $transaction->id, (int) $transaction->pos_session_id, (int) $transaction->pos_device_id, (int) $transaction->location_id, (int) $transaction->cashier_id, $transaction->client_transaction_id);
        $context->validate($actor, $historical);

        return $context;
    }

    public function validate(User $actor, bool $historical = false): PosTransaction
    {
        self::requireTransaction();
        ModuleRegistryLock::acquire();

        return app(PosAccess::class)->context($actor, $this, $historical);
    }

    public function orderSubmissionId(): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'fox-hema:pos:'.$this->clientTransactionId.':order');
    }

    /** @return array{pos_session_id: int, pos_transaction_id: int} */
    public function paymentFields(): array
    {
        return ['pos_session_id' => $this->sessionId, 'pos_transaction_id' => $this->transactionId];
    }

    private static function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('POS context requires the caller transaction.');
        }
    }
}
