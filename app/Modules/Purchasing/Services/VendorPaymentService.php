<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Payments\Services\PaymentFingerprint;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Payments\Services\PaymentMoney;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class VendorPaymentService
{
    /** @var array<int, true> Controlled model write boundary; direct SQL is a trusted administrative escape hatch. */
    private static array $writing = [];

    public static function isWriting(int $id): bool
    {
        return isset(self::$writing[$id]);
    }

    public function __construct(
        private readonly PaymentMoney $money,
        private readonly PaymentFingerprint $fingerprints,
        private readonly PaymentMethods $methods,
        private readonly VendorPaymentAccess $access,
    ) {}

    /** @param array{supplier_id: int, location_id: int, payment_method_id: int, treasury_account_id: int, payment_date: string, amount: string|int, currency?: string, external_reference?: ?string, notes?: ?string} $data */
    public function createDraft(User $actor, array $data, string $operationKey): VendorPayment
    {
        return DB::transaction(function () use ($actor, $data, $operationKey): VendorPayment {
            $this->lockRegistry();
            $this->validateKey($operationKey);
            $allowed = ['supplier_id', 'location_id', 'payment_method_id', 'treasury_account_id', 'payment_date', 'amount', 'currency', 'external_reference', 'notes'];
            if (array_diff(array_keys($data), $allowed) !== []) {
                $this->invalid('payment', 'Unsupported or protected payment fields.');
            }
            $data = array_replace(['currency' => 'EGP', 'external_reference' => null, 'notes' => null], $data);
            Validator::make($data, [
                'supplier_id' => ['required', 'integer'], 'location_id' => ['required', 'integer'],
                'payment_method_id' => ['required', 'integer'], 'payment_date' => ['required', 'date_format:Y-m-d'],
                'treasury_account_id' => ['required', 'integer', 'min:1'],
                'currency' => ['required', 'string'], 'external_reference' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string', 'max:16000'],
            ])->validate();
            foreach (['supplier_id', 'location_id', 'payment_method_id', 'treasury_account_id'] as $field) {
                $data[$field] = (int) $data[$field];
            }
            $data['amount'] = $this->money->normalize($data['amount'] ?? null, $data['currency']);
            $this->access->operation($actor, PurchasingPermissions::PAYMENTS_CREATE, $data['location_id']);
            app(VendorPaymentTreasury::class)->validateSource($actor, $data['treasury_account_id'], $data['payment_method_id'], $data['external_reference']);
            $hash = $this->fingerprints->make('purchasing', 'vendor_payment_create', $data + ['actor_id' => $actor->id]);
            $existing = VendorPayment::where('idempotency_key', $operationKey)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->match($existing->payload_fingerprint, $hash);

                return $existing;
            }
            if (! Supplier::whereKey($data['supplier_id'])->where('is_active', true)->lockForUpdate()->first()) {
                $this->invalid('supplier_id', 'Select an active supplier.');
            }
            $method = $this->methods->resolve($data['payment_method_id'], $data['external_reference']);
            $payment = VendorPayment::create($data + ['method' => $method->code,
                'idempotency_key' => $operationKey, 'payload_fingerprint' => $hash, 'status' => 'draft', 'created_by' => $actor->id]);
            $this->audit($actor, $payment, 'vendor_payment_draft_created');

            return $payment;
        }, 3);
    }

    /** Posting identity is the immutable draft identity. Repeated posting never creates a second payment. */
    public function post(User $actor, VendorPayment $payment): VendorPayment
    {
        return DB::transaction(function () use ($actor, $payment): VendorPayment {
            $this->lockRegistry();
            $payment = VendorPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->managed($payment);
            $this->access->operation($actor, PurchasingPermissions::PAYMENTS_POST, (int) $payment->location_id);
            if ($payment->treasury_account_id === null) {
                $this->invalid('payment', 'Unlinked historical payments cannot be reposted or adopted. Recreate unposted drafts with an explicit Treasury source.');
            }
            app(VendorPaymentTreasury::class)->validateSource($actor, (int) $payment->treasury_account_id, (int) $payment->payment_method_id, $payment->external_reference, posting: true);
            if ($payment->status === 'posted' && $payment->voided_at === null) {
                app(VendorPaymentTreasury::class)->assertLinked($payment);

                return $payment;
            }
            if ($payment->status !== 'draft') {
                $this->invalid('status', 'Only draft vendor payments can be posted.');
            }
            $this->money->normalize($payment->amount, $payment->currency);
            $payload = $payment->only(['supplier_id', 'location_id', 'payment_method_id', 'treasury_account_id', 'amount', 'currency', 'external_reference', 'notes']);
            foreach (['supplier_id', 'location_id', 'payment_method_id', 'treasury_account_id'] as $field) {
                $payload[$field] = (int) $payload[$field];
            }
            $payload['payment_date'] = $payment->payment_date->format('Y-m-d');
            $this->match($payment->payload_fingerprint, $this->fingerprints->make('purchasing', 'vendor_payment_create', $payload + ['actor_id' => $payment->created_by]));
            if (! Supplier::whereKey($payment->supplier_id)->where('is_active', true)->lockForUpdate()->first()) {
                $this->invalid('supplier_id', 'Supplier is inactive.');
            }
            $method = $this->methods->resolve((int) $payment->payment_method_id, $payment->external_reference);
            if ($method->code !== $payment->method) {
                $this->invalid('method', 'Payment method evidence changed.');
            }
            $this->write($payment, ['status' => 'posted', 'posted_by' => $actor->id, 'posted_at' => now()]);
            self::$writing[$payment->id] = true;
            try {
                app(TreasuryService::class)->disburseVendorPayment($actor, $payment);
            } finally {
                unset(self::$writing[$payment->id]);
            }
            $this->audit($actor, $payment, 'vendor_payment_posted');

            return $payment->refresh();
        }, 3);
    }

    public function reverse(User $actor, VendorPayment $payment, string $operationKey, string $reason): VendorPayment
    {
        return $this->reversePayment($actor, $payment, $operationKey, $reason, null);
    }

    /** Full recovery only: caller attests that funds were verified as returned to the original account. */
    public function recover(User $actor, VendorPayment $payment, string $operationKey, string $reason, string $recoveryReference): VendorPayment
    {
        Validator::make(['recovery_reference' => trim($recoveryReference)], ['recovery_reference' => ['required', 'string', 'max:191']])->validate();

        return $this->reversePayment($actor, $payment, $operationKey, $reason, trim($recoveryReference));
    }

    private function reversePayment(User $actor, VendorPayment $payment, string $operationKey, string $reason, ?string $recoveryReference): VendorPayment
    {
        return DB::transaction(function () use ($actor, $payment, $operationKey, $reason, $recoveryReference): VendorPayment {
            $this->lockRegistry();
            $payment = VendorPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->managed($payment);
            $this->access->operation($actor, PurchasingPermissions::PAYMENTS_REVERSE, (int) $payment->location_id);
            if ($payment->treasury_account_id !== null) {
                if ($recoveryReference === null) {
                    $this->invalid('recovery_reference', 'Voiding a document cannot return cash. Use verified full recovery with evidence of funds received into the original account.');
                }
                app(VendorPaymentTreasury::class)->authorizeRecovery($actor, $payment);
            } elseif ($recoveryReference !== null) {
                $this->invalid('payment', 'Unlinked historical payments require separate reconciliation; recovery cannot invent a source account.');
            }
            $this->validateKey($operationKey);
            $reason = trim($reason);
            Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:16000']])->validate();
            $payload = ['payment_id' => $payment->id, 'reason' => $reason, 'actor_id' => $actor->id];
            if ($recoveryReference !== null) {
                $payload['recovery_reference'] = $recoveryReference;
            }
            $hash = $this->fingerprints->make('purchasing', 'vendor_payment_reverse', $payload);
            $existing = VendorPayment::where('reversal_key', $operationKey)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->match($existing->reversal_fingerprint, $hash);

                return $existing;
            }
            if ($payment->status !== 'posted' || $payment->voided_at !== null) {
                $this->invalid('status', 'Only an unreversed posted payment can be reversed once.');
            }
            $payment->allocations()->orderBy('vendor_bill_id')->orderBy('id')->lockForUpdate()->get();
            if ($recoveryReference !== null) {
                self::$writing[$payment->id] = true;
                try {
                    app(VendorPaymentTreasury::class)->recover($actor, $payment, $operationKey, $reason, $recoveryReference);
                } finally {
                    unset(self::$writing[$payment->id]);
                }
            }
            $this->write($payment, ['status' => 'void', 'voided_by' => $actor->id, 'voided_at' => now(),
                'reversal_reason' => $reason, 'reversal_key' => $operationKey, 'reversal_fingerprint' => $hash, 'recovery_reference' => $recoveryReference]);
            $this->audit($actor, $payment, 'vendor_payment_reversed');

            return $payment->refresh();
        }, 3);
    }

    public function history(User $actor, int $supplierId): Collection
    {
        return $this->access->scope(VendorPayment::query(), $actor)->where('supplier_id', $supplierId)
            ->with(['allocations' => fn ($query) => $query->orderBy('id')])->orderBy('payment_date')->orderBy('id')->get();
    }

    public function managed(VendorPayment $payment): void
    {
        if ($payment->location_id === null || $payment->payment_method_id === null || $payment->currency !== 'EGP'
            || $payment->idempotency_key === null || $payment->payload_fingerprint === null) {
            $this->invalid('payment', 'Legacy payment ownership and evidence require explicit reconciliation; automatic adoption is forbidden.');
        }
    }

    public function lockRegistry(): void
    {
        ModuleRegistryLock::acquire();
        abort_unless(Module::where('code', 'access_management')->exists(), 403);
    }

    public function validateKey(string $key): void
    {
        Validator::make(['operation_key' => $key], ['operation_key' => ['required', 'uuid']])->validate();
    }

    public function match(?string $stored, string $hash): void
    {
        if (! hash_equals($stored ?? '', $hash)) {
            $this->invalid('operation_key', 'Operation key was already used with a conflicting payload.');
        }
    }

    public function audit(User $actor, VendorPayment $payment, string $event, array $metadata = []): void
    {
        app(AuditLogger::class)->record($event, metadata: ['vendor_payment_id' => $payment->id, 'owner_location_id' => $payment->location_id] + $metadata, actor: $actor);
    }

    public function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** @param array<string, mixed> $values */
    private function write(VendorPayment $payment, array $values): void
    {
        self::$writing[$payment->id] = true;
        try {
            $payment->fill($values)->save();
        } finally {
            unset(self::$writing[$payment->id]);
        }
    }
}
