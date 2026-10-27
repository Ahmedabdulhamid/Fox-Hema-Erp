<?php

namespace App\Modules\Treasury\Services;

use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Payments\Data\PostedPaymentReference;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Payments\Services\CustomerPayments;
use App\Modules\Payments\Services\CustomerRefunds;
use App\Modules\Payments\Services\PaymentFingerprint;
use App\Modules\Payments\Services\PaymentMoney;
use App\Modules\Payments\Services\PaymentsAccess;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Purchasing\Services\VendorPaymentAccess;
use App\Modules\Purchasing\Services\VendorPaymentService;
use App\Modules\Purchasing\Services\VendorPaymentTreasury;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryEntry;
use App\Modules\Treasury\Models\TreasuryOperation;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TreasuryService
{
    /** Controlled model boundary; database triggers additionally freeze posted history. */
    private static int $writing = 0;

    private const FIELDS = ['type', 'account_id', 'destination_account_id', 'amount', 'currency', 'effective_date',
        'source_type', 'source_reference', 'reason', 'payment_id', 'opening_account_id', 'reversal_of_id', 'vendor_payment_id'];

    public function __construct(
        private readonly TreasuryAccess $access,
        private readonly PaymentMoney $money,
        private readonly PaymentFingerprint $fingerprints,
    ) {}

    public static function isWriting(): bool
    {
        return self::$writing > 0;
    }

    /** @param array{type: string, account_id: int, destination_account_id?: ?int, amount: string|int, currency?: string, effective_date: string, source_type?: string, source_reference?: string, reason: string} $data */
    public function createDraft(User $actor, array $data, string $operationKey): TreasuryOperation
    {
        return DB::transaction(function () use ($actor, $data, $operationKey): TreasuryOperation {
            $this->lockRegistry();
            if (array_diff(array_keys($data), array_diff(self::FIELDS, ['payment_id', 'opening_account_id', 'reversal_of_id', 'vendor_payment_id'])) !== []) {
                $this->invalid('operation', 'Unsupported or protected Treasury fields.');
            }
            Validator::make($data, ['type' => ['required', Rule::in([
                'opening_balance', 'cash_deposit', 'cash_withdrawal', 'bank_deposit', 'bank_withdrawal', 'transfer',
            ])]])->validate();

            return $this->draft($actor, $data, $operationKey);
        }, 3);
    }

    /** Explicit opt-in only. No historical import, POS adoption, or vendor payment posting. */
    public function createPaymentDraft(User $actor, TreasuryAccount $account, Payment $payment, string $operationKey, string $effectiveDate, string $reason): TreasuryOperation
    {
        return DB::transaction(function () use ($actor, $account, $payment, $operationKey, $effectiveDate, $reason): TreasuryOperation {
            $this->lockRegistry();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->isSettlementReceipt() && (! CustomerPayments::isPosting() || (int) $payment->treasury_account_id !== (int) $account->id)) {
                $this->invalid('payment', 'Customer receipts must be linked atomically by the customer posting workflow.');
            }
            $evidence = PostedPaymentReference::fromPayment($payment);
            $this->validatePayment($actor, $payment, $account->id, $evidence->amount, $evidence->currency);

            return $this->draft($actor, [
                'type' => $payment->direction === 'in' ? 'payment_receipt' : 'payment_disbursement',
                'account_id' => $account->id, 'amount' => $evidence->amount, 'currency' => $evidence->currency,
                'effective_date' => $effectiveDate, 'reason' => $reason,
                'source_type' => 'payment', 'source_reference' => $evidence->uuid, 'payment_id' => $payment->id,
            ], $operationKey);
        }, 3);
    }

    /** Synchronous Purchasing orchestration; no generic Payment or historical adoption. */
    public function disburseVendorPayment(User $actor, VendorPayment $payment): TreasuryOperation
    {
        abort_unless(VendorPaymentService::isWriting((int) $payment->id), 403);

        return DB::transaction(function () use ($actor, $payment): TreasuryOperation {
            $this->lockRegistry();
            $payment = VendorPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'posted' || $payment->voided_at !== null || $payment->treasury_account_id === null
                || $payment->idempotency_key === null || $payment->payload_fingerprint === null) {
                $this->invalid('payment', 'Only explicitly Treasury-backed posted vendor payments can disburse funds.');
            }
            app(VendorPaymentAccess::class)->operation($actor, PurchasingPermissions::PAYMENTS_POST, (int) $payment->location_id);
            app(VendorPaymentTreasury::class)->validateSource($actor, (int) $payment->treasury_account_id, (int) $payment->payment_method_id, $payment->external_reference, posting: true);
            $operation = $this->draft($actor, [
                'type' => 'payment_disbursement', 'account_id' => (int) $payment->treasury_account_id,
                'amount' => $payment->amount, 'currency' => $payment->currency,
                'effective_date' => $payment->payment_date->format('Y-m-d'), 'reason' => 'Vendor payment '.$payment->payment_number,
                'source_type' => 'vendor_payment', 'source_reference' => $payment->uuid, 'vendor_payment_id' => (int) $payment->id,
            ], $payment->idempotency_key);

            return $this->post($actor, $operation);
        }, 3);
    }

    private function validateVendorPayment(User $actor, TreasuryOperation $operation): void
    {
        $payment = VendorPayment::whereKey($operation->vendor_payment_id)->lockForUpdate()->firstOrFail();
        app(VendorPaymentAccess::class)->operation($actor, PurchasingPermissions::PAYMENTS_POST, (int) $payment->location_id);
        app(VendorPaymentTreasury::class)->validateSource($actor, (int) $payment->treasury_account_id, (int) $payment->payment_method_id, $payment->external_reference, posting: true);
        if ($payment->status !== 'posted' || $payment->voided_at !== null || $operation->source_type !== 'vendor_payment'
            || $operation->source_reference !== $payment->uuid || $operation->type !== 'payment_disbursement'
            || $operation->amount !== $payment->amount || $operation->currency !== $payment->currency
            || (int) $operation->account_id !== (int) $payment->treasury_account_id || $operation->payment_id !== null) {
            $this->invalid('payment', 'Vendor payment settlement evidence changed.');
        }
    }

    public function post(User $actor, TreasuryOperation $operation): TreasuryOperation
    {
        return DB::transaction(function () use ($actor, $operation): TreasuryOperation {
            $this->lockRegistry();
            $operation = TreasuryOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($operation->vendor_payment_id !== null) {
                $this->validateVendorPayment($actor, $operation);
            }
            if ($operation->reversal_of_id !== null && TreasuryOperation::whereKey($operation->reversal_of_id)->whereNotNull('vendor_payment_id')->exists()
                && ! VendorPaymentTreasury::isRecovering()) {
                $this->invalid('reversal', 'Use the Purchasing verified recovery workflow for vendor disbursements.');
            }
            $payload = $this->payload($operation);
            $this->match($operation->payload_fingerprint, $this->fingerprint($payload, (int) $operation->created_by));
            if ($operation->payment_id !== null) {
                $payment = Payment::whereKey($operation->payment_id)->lockForUpdate()->firstOrFail();
                $this->validatePayment($actor, $payment, (int) $operation->account_id, $operation->amount, $operation->currency);
                if ($operation->source_reference !== $payment->uuid
                    || $operation->type !== ($payment->direction === 'in' ? 'payment_receipt' : 'payment_disbursement')) {
                    $this->invalid('payment', 'Payment source identity or direction changed.');
                }
            }
            $accounts = $this->accounts($payload);
            $this->authorizeAccounts($actor, TreasuryPermissions::POST, $accounts);
            if ($operation->destination_account_id !== null) {
                $this->authorizeAccounts($actor, TreasuryPermissions::TRANSFER, $accounts);
            }
            if ($operation->type === 'reversal') {
                $this->authorizeAccounts($actor, TreasuryPermissions::REVERSE, $accounts);
            }
            if ($operation->status === 'posted') {
                return $operation;
            }
            if ($operation->status !== 'draft' || $operation->cancelled_at !== null || $operation->entries()->exists()) {
                $this->invalid('operation', 'Only an unposted controlled draft may be posted.');
            }
            $this->validateAccountTypes($payload, $accounts);
            $entries = $this->plannedEntries($operation);
            foreach ($entries as $entry) {
                $balance = $this->ledgerBalance($entry['account_id'], true);
                $next = $entry['direction'] === 'in' ? $balance->plus($operation->amount) : $balance->minus($operation->amount);
                if ($next->isNegative() || $next->isGreaterThanOrEqualTo('10000000000000000')) {
                    $this->invalid('balance', 'Insufficient available funds or balance exceeds DECIMAL(20,4).');
                }
                if ($operation->type === 'opening_balance'
                    && TreasuryEntry::where('account_id', $entry['account_id'])->lockForUpdate()->first() !== null) {
                    $this->invalid('opening_balance', 'Opening balances must precede all account movements.');
                }
            }
            $this->write(function () use ($operation, $entries, $accounts, $actor): void {
                foreach ($entries as $entry) {
                    $account = $accounts->find($entry['account_id']);
                    TreasuryEntry::create($entry + [
                        'operation_id' => $operation->id, 'amount' => $operation->amount, 'currency' => $operation->currency,
                        'ownership' => $account->ownership, 'location_id' => $account->location_id,
                    ]);
                }
                $operation->fill(['status' => 'posted', 'posted_by' => $actor->id, 'posted_at' => now()])->save();
            });
            $this->audit($actor, $operation, 'treasury_operation_posted');

            return $operation->refresh();
        }, 3);
    }

    /** Cancellation preserves the unposted draft and its source identity; it never returns money. */
    public function cancel(User $actor, TreasuryOperation $operation, string $operationKey, string $reason): TreasuryOperation
    {
        return DB::transaction(function () use ($actor, $operation, $operationKey, $reason): TreasuryOperation {
            $this->lockRegistry();
            $operation = TreasuryOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $accounts = $this->accounts($this->payload($operation));
            $this->authorizeAccounts($actor, TreasuryPermissions::CREATE, $accounts);
            $this->validateKey($operationKey);
            $reason = trim($reason);
            Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:16000']])->validate();
            $fingerprint = $this->fingerprints->make('treasury', 'cancel', ['operation_id' => $operation->id, 'reason' => $reason, 'actor_id' => $actor->id]);
            if ($operation->cancelled_at !== null) {
                if ($operation->cancellation_key !== $operationKey) {
                    $this->invalid('operation', 'This draft was already cancelled.');
                }
                $this->match($operation->cancellation_fingerprint, $fingerprint);

                return $operation;
            }
            if ($operation->status !== 'draft' || $operation->entries()->exists()
                || $operation->type === 'opening_balance' || $operation->vendor_payment_id !== null || $operation->payment_id !== null || $operation->reversal_of_id !== null) {
                $this->invalid('operation', 'Only an unposted standalone Treasury draft may be cancelled.');
            }
            $this->match($operation->payload_fingerprint, $this->fingerprint($this->payload($operation), (int) $operation->created_by));
            $this->write(fn (): bool => $operation->fill(['cancelled_at' => now(), 'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason, 'cancellation_key' => $operationKey, 'cancellation_fingerprint' => $fingerprint])->save());
            $this->audit($actor, $operation, 'treasury_draft_cancelled');

            return $operation->refresh();
        }, 3);
    }

    public function reverse(User $actor, TreasuryOperation $operation, string $operationKey, string $reason): TreasuryOperation
    {
        return DB::transaction(function () use ($actor, $operation, $operationKey, $reason): TreasuryOperation {
            $this->lockRegistry();
            $original = TreasuryOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if (app(CustomerAccess::class)->ready()
                && (($original->payment_id !== null && Payment::whereKey($original->payment_id)->where(fn ($query) => $query->whereNotNull('customer_id')->orWhereNotNull('treasury_account_id'))->exists())
                || (PaymentRefund::where('treasury_operation_id', $original->id)->exists() && ! CustomerRefunds::isRecovering()))) {
                $this->invalid('reversal', 'Use customer allocation release and refund workflows; customer financial evidence cannot be reversed independently.');
            }
            if ($original->vendor_payment_id !== null && ! VendorPaymentTreasury::isRecovering()) {
                $this->invalid('reversal', 'Use the Purchasing verified recovery workflow for vendor disbursements.');
            }
            if ($original->status !== 'posted' || $original->reversal_of_id !== null) {
                $this->invalid('reversal', 'Only an original posted operation can be reversed once.');
            }
            $accounts = $this->accounts($this->payload($original));
            $this->authorizeAccounts($actor, TreasuryPermissions::REVERSE, $accounts);
            $this->validateKey($operationKey);
            $reason = trim($reason);
            Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:16000']])->validate();
            $existing = TreasuryOperation::where('idempotency_key', $operationKey)->lockForUpdate()->first();
            if ($existing !== null) {
                $expected = $this->payload($existing);
                $expected['reason'] = $reason;
                $expected['reversal_of_id'] = (int) $original->id;
                $expected['source_reference'] = $original->uuid;
                $this->match($existing->payload_fingerprint, $this->fingerprint($expected, (int) $actor->id));
                if ($existing->type !== 'reversal') {
                    $this->invalid('idempotency_key', 'Key belongs to a different operation.');
                }

                return $this->post($actor, $existing);
            }
            if (TreasuryOperation::where('reversal_of_id', $original->id)->lockForUpdate()->first() !== null) {
                $this->invalid('reversal', 'This operation already has a compensating reversal.');
            }
            $data = [
                'type' => 'reversal', 'account_id' => (int) $original->account_id,
                'destination_account_id' => $original->destination_account_id === null ? null : (int) $original->destination_account_id,
                'amount' => $original->amount, 'currency' => $original->currency, 'effective_date' => now()->format('Y-m-d'),
                'source_type' => 'treasury_reversal', 'source_reference' => $original->uuid, 'reason' => $reason,
                'reversal_of_id' => (int) $original->id, 'payment_id' => null, 'opening_account_id' => null,
            ];
            $reversal = $this->insert($actor, $data, $operationKey);

            return $this->post($actor, $reversal);
        }, 3);
    }

    public function balance(User $actor, TreasuryAccount $account): string
    {
        $account = TreasuryAccount::findOrFail($account->id);
        $this->access->operation($actor, TreasuryPermissions::BALANCES, $account->location_id);

        return (string) $this->ledgerBalance((int) $account->id, false);
    }

    public function history(User $actor, TreasuryAccount $account): Collection
    {
        $account = TreasuryAccount::findOrFail($account->id);
        $this->access->authorize($actor, TreasuryPermissions::VIEW, $account->location_id);

        return TreasuryEntry::where('account_id', $account->id)->with('operation')->orderBy('id')->get();
    }

    /** @param array<string, mixed> $data */
    private function draft(User $actor, array $data, string $key): TreasuryOperation
    {
        $this->validateKey($key);
        $data = array_replace(['destination_account_id' => null, 'currency' => 'EGP', 'source_type' => null,
            'source_reference' => null, 'payment_id' => null, 'opening_account_id' => null, 'reversal_of_id' => null, 'vendor_payment_id' => null], $data);
        Validator::make($data, [
            'account_id' => ['required', 'integer'], 'destination_account_id' => ['nullable', 'integer', 'different:account_id'],
            'effective_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:16000'], 'currency' => ['required', 'string'],
        ])->validate();
        foreach (['account_id', 'destination_account_id', 'payment_id', 'vendor_payment_id'] as $field) {
            $data[$field] = $data[$field] === null ? null : (int) $data[$field];
        }
        $data['amount'] = $this->money->normalize($data['amount'] ?? null, $data['currency']);
        $data['reason'] = trim($data['reason']);
        if ($data['reason'] === '') {
            $this->invalid('reason', 'A business reason is required.');
        }
        if ($data['type'] === 'opening_balance') {
            $data['opening_account_id'] = $data['account_id'];
            $data['source_type'] = 'opening_balance';
            $data['source_reference'] = (string) $data['account_id'];
        } elseif ($data['type'] === 'transfer') {
            $data['source_type'] = 'internal_transfer';
            $data['source_reference'] = $key;
        } elseif ($data['payment_id'] === null && $data['vendor_payment_id'] === null) {
            Validator::make($data, [
                'source_type' => ['required', Rule::in(['external_capital', 'external_expense', 'external_other'])],
                'source_reference' => ['required', 'string', 'max:191'],
            ])->validate();
        }
        $accounts = $this->accounts($data);
        $this->authorizeAccounts($actor, TreasuryPermissions::CREATE, $accounts);
        if ($data['type'] === 'transfer') {
            $this->authorizeAccounts($actor, TreasuryPermissions::TRANSFER, $accounts);
        }
        $this->validateAccountTypes($data, $accounts);
        $hash = $this->fingerprint($data, (int) $actor->id);
        $existing = TreasuryOperation::where('idempotency_key', $key)->lockForUpdate()->first();
        if ($existing !== null) {
            $this->match($existing->payload_fingerprint, $hash);

            return $existing;
        }
        if (TreasuryOperation::where('source_type', $data['source_type'])->where('source_reference', $data['source_reference'])->lockForUpdate()->first() !== null
            || ($data['payment_id'] !== null && TreasuryOperation::where('payment_id', $data['payment_id'])->lockForUpdate()->first() !== null)) {
            $this->invalid('source_reference', 'This source operation already has Treasury evidence; reuse its original operation key.');
        }

        return $this->insert($actor, $data, $key);
    }

    /** @param array<string, mixed> $data */
    private function insert(User $actor, array $data, string $key): TreasuryOperation
    {
        $operation = $this->write(fn (): TreasuryOperation => TreasuryOperation::create($data + [
            'idempotency_key' => $key, 'payload_fingerprint' => $this->fingerprint($data, (int) $actor->id),
            'status' => 'draft', 'created_by' => $actor->id,
        ]));
        $this->audit($actor, $operation, 'treasury_draft_created');

        return $operation;
    }

    /** @param array<string, mixed> $data */
    private function accounts(array $data): Collection
    {
        $ids = array_values(array_filter([$data['account_id'], $data['destination_account_id'] ?? null]));
        $ids = array_unique($ids);
        sort($ids, SORT_NUMERIC);
        $accounts = new Collection;
        foreach ($ids as $id) {
            $account = TreasuryAccount::whereKey($id)->lockForUpdate()->first();
            if ($account === null || ! $account->is_active || $account->currency !== $data['currency']) {
                $this->invalid('account', 'Select active Treasury accounts in the operation currency.');
            }
            $accounts->push($account);
        }

        return $accounts;
    }

    private function authorizeAccounts(User $actor, string $permission, Collection $accounts): void
    {
        foreach ($accounts as $account) {
            $this->access->operation($actor, $permission, $account->location_id);
        }
    }

    /** @param array<string, mixed> $data */
    private function validateAccountTypes(array $data, Collection $accounts): void
    {
        $bank = $accounts->find($data['account_id'])->type === 'bank';
        if (($data['type'] === 'transfer') !== ($data['destination_account_id'] !== null) && $data['type'] !== 'reversal') {
            $this->invalid('destination_account_id', 'Only transfers have a distinct destination account.');
        }
        if ((str_starts_with($data['type'], 'bank_') && ! $bank)
            || (str_starts_with($data['type'], 'cash_') && $bank)) {
            $this->invalid('type', 'Movement type must match the cashbox or bank account.');
        }
    }

    /** @return list<array{account_id: int, direction: string}> */
    private function plannedEntries(TreasuryOperation $operation): array
    {
        if ($operation->type === 'reversal') {
            $original = TreasuryOperation::whereKey($operation->reversal_of_id)->lockForUpdate()->firstOrFail();
            if ($original->status !== 'posted' || $original->reversal_of_id !== null) {
                $this->invalid('reversal', 'Invalid original movement.');
            }

            return $original->entries()->orderBy('account_id')->lockForUpdate()->get()->map(fn (TreasuryEntry $entry): array => [
                'account_id' => (int) $entry->account_id, 'direction' => $entry->direction === 'in' ? 'out' : 'in',
            ])->all();
        }
        if ($operation->type === 'transfer') {
            return [
                ['account_id' => (int) $operation->account_id, 'direction' => 'out'],
                ['account_id' => (int) $operation->destination_account_id, 'direction' => 'in'],
            ];
        }

        return [['account_id' => (int) $operation->account_id,
            'direction' => in_array($operation->type, ['cash_withdrawal', 'bank_withdrawal', 'payment_disbursement'], true) ? 'out' : 'in']];
    }

    /** A locking read sees latest committed entries even inside a repeatable-read outer transaction. */
    private function ledgerBalance(int $accountId, bool $locking): BigDecimal
    {
        $query = DB::table('treasury_entries')
            ->join('treasury_operations', 'treasury_operations.id', '=', 'treasury_entries.operation_id')
            ->where('treasury_entries.account_id', $accountId)->where('treasury_operations.status', 'posted')
            ->select('treasury_entries.amount', 'treasury_entries.direction')->orderBy('treasury_entries.id');
        if ($locking) {
            $query->lockForUpdate();
        }
        $balance = BigDecimal::of('0.0000');
        foreach ($query->get() as $entry) {
            $balance = $entry->direction === 'in' ? $balance->plus($entry->amount) : $balance->minus($entry->amount);
        }

        return $balance;
    }

    private function validatePayment(User $actor, Payment $payment, int $accountId, string $amount, string $currency): void
    {
        $reference = PostedPaymentReference::fromPayment($payment);
        app(PaymentsAccess::class)->authorize($actor, PaymentsPermissions::VIEW, $reference->locationId);
        $account = TreasuryAccount::findOrFail($accountId);
        if ($payment->pos_session_id !== null || $payment->pos_transaction_id !== null || $reference->reversalOfId !== null
            || Payment::where('reversal_of_id', $payment->id)->lockForUpdate()->first() !== null
            || $account->location_id !== $reference->locationId || $account->currency !== $currency
            || $reference->currency !== $currency || $reference->amount !== $amount) {
            $this->invalid('payment', 'Payment must be original, posted, unreversed, and match the account location, amount, and currency; POS adoption is forbidden.');
        }
    }

    /** @return array<string, mixed> */
    private function payload(TreasuryOperation $operation): array
    {
        $data = $operation->only(self::FIELDS);
        foreach (['account_id', 'destination_account_id', 'payment_id', 'opening_account_id', 'reversal_of_id', 'vendor_payment_id'] as $field) {
            $data[$field] = $data[$field] === null ? null : (int) $data[$field];
        }
        $data['effective_date'] = $operation->effective_date->format('Y-m-d');

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function fingerprint(array $data, int $actorId): string
    {
        if (($data['vendor_payment_id'] ?? null) === null) {
            unset($data['vendor_payment_id']);
        }

        return $this->fingerprints->make('treasury', 'operation', $data + ['actor_id' => $actorId]);
    }

    private function match(string $stored, string $expected): void
    {
        if (! hash_equals($stored, $expected)) {
            $this->invalid('idempotency_key', 'Conflicting retry or modified draft evidence.');
        }
    }

    private function validateKey(string $key): void
    {
        Validator::make(['operation_key' => $key], ['operation_key' => ['required', 'uuid']])->validate();
    }

    private function lockRegistry(): void
    {
        ModuleRegistryLock::acquire();
        abort_unless(Module::where('code', 'access_management')->exists(), 403);
    }

    private function write(Closure $callback): mixed
    {
        self::$writing++;
        try {
            return $callback();
        } finally {
            self::$writing--;
        }
    }

    private function audit(User $actor, TreasuryOperation $operation, string $event): void
    {
        app(AuditLogger::class)->record($event, metadata: [
            'treasury_operation_id' => $operation->id, 'account_id' => $operation->account_id,
            'destination_account_id' => $operation->destination_account_id, 'reversal_of_id' => $operation->reversal_of_id,
        ], actor: $actor);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
