<?php

namespace App\Modules\POS\Data;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Services\PaymentMoney;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\POS\Services\PosAccess;
use App\Modules\POS\Services\PosPermissions;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Services\SalesCreditNoteService;
use App\Modules\Treasury\Models\TreasuryAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class PosRefundContext
{
    private function __construct(public int $sessionId, public int $transactionId, public int $paymentId, public int $creditId) {}

    public static function fromPayment(User $actor, Payment $payment, SalesCreditNote $credit, bool $historical = false): self
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('POS refund context requires the caller transaction.');
        }
        app(PosAccess::class)->authorize($actor, PosPermissions::VIEW, (int) $payment->location_id);
        app(PosAccess::class)->authorize($actor, PosPermissions::RETURNS, (int) $payment->location_id);
        $transaction = PosTransaction::whereKey($payment->pos_transaction_id)->lockForUpdate()->first();
        $session = PosSession::whereKey($payment->pos_session_id)->lockForUpdate()->first();
        $device = $session === null ? null : PosDevice::whereKey($session->pos_device_id)->lockForUpdate()->first();
        $account = $payment->treasury_account_id === null
            ? null
            : TreasuryAccount::whereKey($payment->treasury_account_id)->lockForUpdate()->first();
        $method = PaymentMethod::whereKey($payment->payment_method_id)->lockForUpdate()->first();
        $validAccount = $method?->type === 'cash'
            ? $account !== null
                && (int) $session?->cash_treasury_account_id === (int) $account->id
                && (int) $account->location_id === (int) $payment->location_id
                && $account->currency === 'EGP'
                && $account->ownership === 'location'
                && in_array($account->type, ['branch_cashbox', 'location_cashbox'], true)
                && ($historical || $account->is_active)
            : $payment->treasury_account_id === null && $account === null;
        if ($transaction === null || $session === null || $device === null || $method === null
            || ! $payment->isSettlementReceipt() || $payment->status !== 'posted' || $payment->direction !== 'in' || $payment->currency !== 'EGP'
            || ! in_array($method->type, PaymentMethod::TYPES, true) || $credit->status !== 'posted' || $credit->cancelled_at !== null
            || ! $validAccount
            || $credit->salesReturn?->status !== 'posted'
            || (int) $credit->location_id !== (int) $payment->location_id
            || (int) $transaction->sales_invoice_id !== (int) $credit->sales_invoice_id
            || (int) $credit->salesReturn->sales_order_id !== (int) $transaction->sales_order_id
            || $payment->source_document_type !== 'sales_invoice' || $payment->source_document_reference !== $credit->salesInvoice->uuid
            || $transaction->transaction_type !== 'sale' || $transaction->sync_status !== 'completed'
            || ! collect($transaction->request_payload['tenders'] ?? [])->contains(fn (array $tender): bool => (int) ($tender['payment_method_id'] ?? 0) === (int) $method->id
                && app(PaymentMoney::class)->normalize($tender['amount'] ?? null, 'EGP') === $payment->amount)
            || (int) $transaction->pos_session_id !== (int) $session->id || (int) $transaction->pos_device_id !== (int) $device->id
            || (int) $transaction->cashier_id !== (int) $actor->id || (int) $session->opened_by !== (int) $actor->id
            || (int) $payment->created_by !== (int) $actor->id
            || (int) $transaction->location_id !== (int) $payment->location_id || (int) $session->location_id !== (int) $payment->location_id
            || (int) $device->location_id !== (int) $payment->location_id
            || $session->currency !== 'EGP'
            || (! $historical && ($session->status !== 'open' || $session->closing_started_at !== null || $session->closed_at !== null
                || ! $device->is_active || $device->revoked_at !== null))) {
            self::invalid();
        }

        app(SalesCreditNoteService::class)->verifyPosted($credit);

        return new self((int) $session->id, (int) $transaction->id, (int) $payment->id, (int) $credit->id);
    }

    public function validate(User $actor, Payment $payment, SalesCreditNote $credit, bool $historical = false): void
    {
        $current = self::fromPayment($actor, $payment, $credit, $historical);
        if ($current != $this) {
            self::invalid();
        }
    }

    /** @return array{pos_session_id: int, pos_transaction_id: int} */
    public function refundFields(): array
    {
        return ['pos_session_id' => $this->sessionId, 'pos_transaction_id' => $this->transactionId];
    }

    public static function forRefund(User $actor, PaymentRefund $refund, Payment $payment): ?self
    {
        $isPosPayment = $payment->pos_session_id !== null && $payment->pos_transaction_id !== null;
        $requiresPosContext = $isPosPayment && (
            $payment->paymentMethod->type === 'cash' || $payment->treasury_account_id === null
        );
        if (! $requiresPosContext && $refund->pos_session_id === null && $refund->pos_transaction_id === null) {
            return null;
        }
        if (! $requiresPosContext || $refund->sales_credit_note_id === null
            || $refund->pos_session_id === null || $refund->pos_transaction_id === null) {
            self::invalid();
        }
        $context = self::fromPayment($actor, $payment, $refund->creditNote, $refund->status === 'posted');
        if ((int) $refund->pos_session_id !== $context->sessionId || (int) $refund->pos_transaction_id !== $context->transactionId
            || (int) $refund->created_by !== (int) $actor->id
            || $refund->treasury_account_id !== $payment->treasury_account_id
            || (int) $refund->payment_method_id !== (int) $payment->payment_method_id) {
            self::invalid();
        }

        return $context;
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['pos_context' => __('pos.validation.cash_refund_context')]);
    }
}
