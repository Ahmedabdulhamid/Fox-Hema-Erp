<?php

namespace App\Modules\Payments\Services;

use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Payments\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    /** @var array<int, true> Internal model-event boundary, following bill posting conventions. */
    private static array $writing = [];

    public static function isWriting(int $id): bool
    {
        return isset(self::$writing[$id]);
    }

    public function __construct(
        private readonly PaymentMoney $money,
        private readonly PaymentFingerprint $fingerprints,
        private readonly PaymentMethods $methods,
        private readonly PaymentsAccess $access,
    ) {}

    /**
     * Payments-owned infrastructure only; domain services retain invoice validation and allocations.
     * Source references are informational and never settle domain documents.
     *
     * @param  array{location_id: int, payment_method_id: int, direction: string, amount: string|int, currency?: string, external_reference?: ?string, source_document_type?: ?string, source_document_reference?: ?string, notes?: ?string}  $data
     */
    public function createDraft(User $actor, array $data, string $operationKey): Payment
    {
        return DB::transaction(function () use ($actor, $data, $operationKey): Payment {
            $this->lockRegistry();
            Validator::make(['key' => $operationKey], ['key' => ['required', 'uuid']], [], __('payments.validation_attributes'))->validate();
            $allowed = ['location_id', 'payment_method_id', 'direction', 'amount', 'currency', 'external_reference', 'source_document_type', 'source_document_reference', 'notes'];
            if (array_diff(array_keys($data), $allowed) !== []) {
                $this->invalid('payment', 'Unsupported or protected payment fields.');
            }
            $data = array_replace(['currency' => 'EGP', 'external_reference' => null, 'source_document_type' => null, 'source_document_reference' => null, 'notes' => null], $data);
            Validator::make($data, [
                'location_id' => ['required', 'integer'], 'payment_method_id' => ['required', 'integer'],
                'direction' => ['required', Rule::in(['in', 'out'])], 'currency' => ['required', 'string'],
                'external_reference' => ['nullable', 'string', 'max:191'],
                'source_document_type' => ['nullable', 'required_with:source_document_reference', 'string', 'max:80'],
                'source_document_reference' => ['nullable', 'required_with:source_document_type', 'string', 'max:191'],
                'notes' => ['nullable', 'string', 'max:16000'],
            ], [], __('payments.validation_attributes'))->validate();
            $data['amount'] = $this->money->normalize($data['amount'] ?? null, $data['currency']);
            $data['location_id'] = (int) $data['location_id'];
            $data['payment_method_id'] = (int) $data['payment_method_id'];
            $this->authorizeOperation($actor, PaymentsPermissions::CREATE, $data['location_id']);
            $hash = $this->fingerprints->make('payments', 'create', $data + ['actor_id' => $actor->id]);
            $existing = Payment::where('idempotency_key', $operationKey)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->matchFingerprint($existing, $hash);

                return $existing;
            }
            $this->methods->resolve($data['payment_method_id'], $data['external_reference']);

            return Payment::create($data + [
                'payment_number' => 'PAY-'.Str::uuid7(), 'idempotency_key' => $operationKey,
                'payload_fingerprint' => $hash, 'origin_module' => 'payments', 'status' => 'draft', 'created_by' => $actor->id,
            ]);
        }, 3);
    }

    public function post(User $actor, Payment $payment): Payment
    {
        return DB::transaction(function () use ($actor, $payment): Payment {
            $this->lockRegistry();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->authorizeOperation($actor, PaymentsPermissions::POST, (int) $payment->location_id);
            $this->owned($payment);
            if ($payment->isSettlementReceipt() && ! CustomerPayments::isPosting()) {
                $this->invalid('payment', 'Use the customer payment posting workflow.');
            }
            if ($payment->status === 'posted') {
                return $payment;
            }
            if ($payment->status !== 'draft' || $payment->reversal_of_id !== null) {
                $this->invalid('status', 'Only a foundation draft can be posted here.');
            }
            $this->methods->resolve((int) $payment->payment_method_id, $payment->external_reference);
            $this->money->normalize($payment->amount, $payment->currency);
            $payload = $payment->only(['location_id', 'payment_method_id', 'direction', 'amount', 'currency', 'external_reference', 'source_document_type', 'source_document_reference', 'notes']);
            if ($payment->isSettlementReceipt()) {
                $payload += $payment->only(['customer_id', 'treasury_account_id']);
                $payload['payment_date'] = $payment->payment_date->format('Y-m-d');
            }
            $this->matchFingerprint($payment, $this->fingerprints->make('payments', 'create', $payload + ['actor_id' => $payment->created_by]));

            return $this->markPosted($payment, $actor);
        }, 3);
    }

    /** Full administrative reversal; refunds and allocated transactions belong to domain workflows. */
    public function reverse(User $actor, Payment $payment, string $operationKey, string $reason): Payment
    {
        return DB::transaction(function () use ($actor, $payment, $operationKey, $reason): Payment {
            $this->lockRegistry();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->authorizeOperation($actor, PaymentsPermissions::REVERSE, (int) $payment->location_id);
            $this->owned($payment);
            if ($payment->isSettlementReceipt()) {
                $this->invalid('payment', 'Use allocation release and customer refunds to correct customer receipts.');
            }
            $reason = trim($reason);
            Validator::make(['key' => $operationKey, 'reason' => $reason], ['key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:16000']], [], __('payments.validation_attributes'))->validate();
            if ($payment->status !== 'posted' || $payment->reversal_of_id !== null) {
                $this->invalid('payment', 'Only an original posted foundation payment can be reversed.');
            }
            $this->money->normalize($payment->amount, $payment->currency);
            $hash = $this->fingerprints->make('payments', 'reverse', ['payment_uuid' => $payment->uuid, 'reason' => $reason, 'actor_id' => $actor->id]);
            $existing = Payment::where('idempotency_key', $operationKey)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->matchFingerprint($existing, $hash);

                return $existing;
            }
            if ($payment->reversal()->exists()) {
                $this->invalid('payment', 'This payment already has a reversal.');
            }
            if (DB::table('payment_allocations')->where('payment_id', $payment->id)->exists()
                || DB::table('payment_refunds')->where('payment_id', $payment->id)->exists()) {
                $this->invalid('payment', 'Allocated or refunded payments require their domain correction workflow.');
            }
            $reversal = Payment::create($payment->only(['location_id', 'payment_method_id', 'amount', 'currency', 'external_reference', 'source_document_type', 'source_document_reference']) + [
                'direction' => $payment->direction === 'in' ? 'out' : 'in', 'origin_module' => 'payments',
                'payment_number' => 'REV-'.Str::uuid7(), 'idempotency_key' => $operationKey,
                'payload_fingerprint' => $hash, 'status' => 'draft', 'reversal_of_id' => $payment->id,
                'reversal_reason' => $reason, 'created_by' => $actor->id,
            ]);

            return $this->markPosted($reversal, $actor);
        }, 3);
    }

    private function markPosted(Payment $payment, User $actor): Payment
    {
        self::$writing[$payment->id] = true;
        try {
            $payment->fill(['status' => 'posted', 'posted_by' => $actor->id, 'posted_at' => now(), 'paid_at' => now()])->save();
        } finally {
            unset(self::$writing[$payment->id]);
        }

        return $payment->refresh();
    }

    private function matchFingerprint(Payment $payment, string $hash): void
    {
        if ($payment->origin_module !== 'payments' || ! hash_equals($payment->payload_fingerprint ?? '', $hash)) {
            $this->invalid('idempotency_key', 'Operation key was already used with different data or ownership.');
        }
    }

    private function owned(Payment $payment): void
    {
        if ($payment->origin_module !== 'payments' || $payment->pos_session_id !== null || $payment->pos_transaction_id !== null) {
            $this->invalid('payment', 'This transaction belongs to another workflow.');
        }
    }

    private function lockRegistry(): void
    {
        ModuleRegistryLock::acquire();
        abort_unless(Module::where('code', 'access_management')->exists(), 403);
    }

    private function authorizeOperation(User $actor, string $permission, int $locationId): void
    {
        $this->access->authorize($actor, PaymentsPermissions::VIEW, $locationId);
        $this->access->authorize($actor, $permission, $locationId);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
