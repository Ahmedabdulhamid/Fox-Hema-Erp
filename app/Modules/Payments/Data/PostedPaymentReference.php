<?php

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentMoney;
use Illuminate\Validation\ValidationException;

/** Evidence only; no Treasury implementation is bound or invoked by Payments. */
final readonly class PostedPaymentReference
{
    private function __construct(
        public string $uuid,
        public string $originModule,
        public int $locationId,
        public int $paymentMethodId,
        public string $direction,
        public string $amount,
        public string $currency,
        public int $actorId,
        public string $postedAt,
        public string $operationKey,
        public string $payloadFingerprint,
        public ?string $externalReference,
        public ?string $sourceDocumentType,
        public ?string $sourceDocumentReference,
        public ?int $reversalOfId,
    ) {}

    public static function fromPayment(Payment $payment): self
    {
        $payment = $payment->fresh() ?? throw ValidationException::withMessages(['payment' => __('Payment no longer exists.')]);
        if ($payment->status !== 'posted' || $payment->posted_at === null || $payment->posted_by === null || $payment->currency !== 'EGP' || $payment->origin_module !== 'payments'
            || $payment->idempotency_key === null || $payment->payload_fingerprint === null || $payment->uuid === null) {
            throw ValidationException::withMessages(['payment' => __('A posted foundation payment is required.')]);
        }
        app(PaymentMoney::class)->normalize($payment->amount, $payment->currency);

        return new self($payment->uuid, $payment->origin_module, (int) $payment->location_id, (int) $payment->payment_method_id,
            $payment->direction, $payment->amount, $payment->currency, (int) $payment->posted_by, $payment->posted_at->toIso8601String(),
            $payment->idempotency_key, $payment->payload_fingerprint, $payment->external_reference,
            $payment->source_document_type, $payment->source_document_reference, $payment->reversal_of_id);
    }
}
