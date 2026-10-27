<?php

namespace App\Modules\Treasury\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Contracts\TreasuryPaymentGateway;
use App\Modules\Payments\Data\PostedPaymentReference;
use App\Modules\Payments\Models\Payment;
use App\Modules\Treasury\Models\TreasuryAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Construct explicitly with approved account/actor context; never bind globally or invoke automatically. */
final class ExplicitTreasuryPaymentGateway implements TreasuryPaymentGateway
{
    public function __construct(
        private readonly User $actor,
        private readonly TreasuryAccount $account,
        private readonly string $operationKey,
        private readonly string $effectiveDate,
        private readonly string $reason,
    ) {}

    public function record(PostedPaymentReference $payment): string
    {
        return DB::transaction(function () use ($payment): string {
            $record = Payment::where('uuid', $payment->uuid)->firstOrFail();
            if (PostedPaymentReference::fromPayment($record) != $payment) {
                throw ValidationException::withMessages(['payment' => 'Posted payment evidence changed.']);
            }
            $service = app(TreasuryService::class);
            $draft = $service->createPaymentDraft($this->actor, $this->account, $record, $this->operationKey, $this->effectiveDate, $this->reason);

            return $service->post($this->actor, $draft)->uuid;
        }, 3);
    }
}
