<?php

namespace App\Modules\POS\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\POS\Data\PosContext;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Treasury\Models\TreasuryAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosAccess
{
    /**
     * @return list<int>
     */
    public function branchIds(User $actor, string $permission): array
    {
        if ($actor->isGlobalSuperAdmin()) {
            app(ModuleAccess::class)->authorize($actor, 'pos', $permission);

            return Location::query()
                ->where('type', 'branch')
                ->where('is_active', true)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        $locationId = (int) getPermissionsTeamId();
        $this->authorize($actor, $permission, $locationId);

        return [$locationId];
    }

    public function can(User $actor, string $permission): bool
    {
        if (! $actor->exists || $actor->trashed() || ! RequestAuthorizationFacts::actorIsActive($actor)
            || ! in_array($permission, PosPermissions::all(), true)
            || ! RequestAuthorizationFacts::remember('pos-module-permission:'.$permission,
                fn (): bool => Module::query()->where('code', 'pos')->where('is_active', true)
                    ->whereHas('permissions', fn ($query) => $query->where('permissions.name', $permission)->where('guard_name', 'web'))
                    ->exists())) {
            return false;
        }

        RequestAuthorizationFacts::refreshPermissionRelations($actor);

        return $actor->isGlobalSuperAdmin() || $actor->can($permission);
    }

    public function authorize(User $actor, string $permission, int $locationId): void
    {
        abort_unless($actor->exists && ! $actor->trashed() && RequestAuthorizationFacts::actorIsActive($actor) && in_array($permission, PosPermissions::all(), true), 403);
        RequestAuthorizationFacts::refreshPermissionRelations($actor);
        app(ModuleAccess::class)->authorize($actor, 'pos', $permission);
        abort_unless(RequestAuthorizationFacts::remember('pos-active-branch:'.$locationId,
            fn (): bool => Location::whereKey($locationId)->where('type', 'branch')->where('is_active', true)->exists()), 403);
        $isGlobalDeviceManager = $permission === PosPermissions::DEVICES_MANAGE && $actor->isGlobalSuperAdmin();
        abort_unless(($isGlobalDeviceManager || (int) getPermissionsTeamId() === $locationId)
            && ($actor->isGlobalSuperAdmin() || RequestAuthorizationFacts::actorHasLocation($actor, $locationId)), 403);
    }

    public function context(User $actor, PosContext $context, bool $historical = false): PosTransaction
    {
        $this->authorize($actor, PosPermissions::VIEW, $context->locationId);
        $this->authorize($actor, PosPermissions::CHECKOUT, $context->locationId);
        $transaction = PosTransaction::whereKey($context->transactionId)->lockForUpdate()->firstOrFail();
        $session = PosSession::whereKey($context->sessionId)->lockForUpdate()->firstOrFail();
        $device = PosDevice::whereKey($context->deviceId)->lockForUpdate()->firstOrFail();
        if ((int) $actor->id !== $context->cashierId
            || (int) $transaction->cashier_id !== $context->cashierId
            || (int) $session->opened_by !== $context->cashierId || (int) $transaction->pos_session_id !== $context->sessionId
            || (int) $transaction->pos_device_id !== $context->deviceId || (int) $session->pos_device_id !== $context->deviceId
            || (int) $transaction->location_id !== $context->locationId || (int) $session->location_id !== $context->locationId
            || (int) $device->location_id !== $context->locationId || $transaction->client_transaction_id !== $context->clientTransactionId
            || $transaction->transaction_type !== 'sale' || ! in_array($transaction->sync_status, $historical ? ['processing', 'completed'] : ['processing'], true)
            || (! $historical && (! $device->is_active || $device->revoked_at !== null
                || (! PosCheckoutService::isPostingLate((int) $session->id) && ($session->status !== 'open' || $session->closing_started_at !== null || $session->closed_at !== null))))
            || $session->currency !== 'EGP' || $session->cash_treasury_account_id === null) {
            $this->invalid();
        }

        $drawer = TreasuryAccount::whereKey($session->cash_treasury_account_id)->lockForUpdate()->firstOrFail();
        if ((int) $drawer->location_id !== $context->locationId || $drawer->currency !== 'EGP' || $drawer->ownership !== 'location'
            || ! in_array($drawer->type, ['branch_cashbox', 'location_cashbox'], true) || (! $historical && ! $drawer->is_active)) {
            $this->invalid();
        }

        return $transaction;
    }

    public function invoice(User $actor, PosContext $context, SalesInvoice $invoice, bool $historical = false): void
    {
        $transaction = $context->validate($actor, $historical);
        $order = $invoice->salesOrder()->lockForUpdate()->firstOrFail();
        if ((int) $invoice->location_id !== $context->locationId || $invoice->status !== 'posted' || $invoice->cancelled_at !== null
            || $order->channel !== 'pos' || $order->uuid !== $context->orderSubmissionId() || (int) $order->location_id !== $context->locationId
            || ($transaction->sales_order_id !== null && (int) $transaction->sales_order_id !== (int) $order->id)
            || ($transaction->sales_invoice_id !== null && (int) $transaction->sales_invoice_id !== (int) $invoice->id)) {
            $this->invalid();
        }
    }

    public function tender(User $actor, PosContext $context, int $methodId, ?int $accountId): void
    {
        $context->validate($actor);
        $configuration = DB::table('pos_device_payment_methods')
            ->where('pos_device_id', $context->deviceId)
            ->where('payment_method_id', $methodId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();
        $method = PaymentMethod::whereKey($methodId)->lockForUpdate()->firstOrFail();
        $session = PosSession::findOrFail($context->sessionId);
        $cashAccount = $method->type === 'cash' && $accountId !== null
            ? TreasuryAccount::whereKey($accountId)->lockForUpdate()->first()
            : null;
        if ($configuration === null || ! $method->is_active || ! $method->available_in_pos
            || ! in_array($method->type, PaymentMethod::TYPES, true)) {
            throw ValidationException::withMessages([
                'tenders' => __('pos.validation.payment_method_unavailable'),
            ]);
        }
        if (($method->type === 'cash' && (
            $cashAccount === null
            || (int) $configuration->treasury_account_id !== $accountId
            || ! $cashAccount->is_active
            || (int) $cashAccount->location_id !== $context->locationId
            || $cashAccount->ownership !== 'location'
            || $cashAccount->currency !== 'EGP'
            || ! in_array($cashAccount->type, ['branch_cashbox', 'location_cashbox'], true)
            || (int) $session->cash_treasury_account_id !== $accountId
        ))
        || ($method->type !== 'cash' && $accountId !== null)) {
            $this->invalid();
        }
    }

    public function payment(User $actor, Payment $payment, bool $historical = false): void
    {
        if ($payment->pos_session_id === null && $payment->pos_transaction_id === null) {
            return;
        }
        if ($payment->pos_session_id === null || $payment->pos_transaction_id === null || ! $payment->isSettlementReceipt()
            || $payment->direction !== 'in' || $payment->source_document_type !== 'sales_invoice') {
            $this->invalid();
        }
        $transaction = PosTransaction::whereKey($payment->pos_transaction_id)->lockForUpdate()->firstOrFail();
        $context = PosContext::fromTransactionForPayment($actor, $transaction, $historical);
        if ((int) $payment->pos_session_id !== $context->sessionId || (int) $payment->location_id !== $context->locationId || (int) $payment->created_by !== $context->cashierId) {
            $this->invalid();
        }
        $invoice = SalesInvoice::where('uuid', $payment->source_document_reference)->lockForUpdate()->firstOrFail();
        $this->invoice($actor, $context, $invoice, $historical);
        if (! $historical) {
            $this->tender(
                $actor,
                $context,
                (int) $payment->payment_method_id,
                $payment->treasury_account_id === null ? null : (int) $payment->treasury_account_id,
            );
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['pos_context' => __('POS device, shift, cashier, document or tender context is invalid.')]);
    }
}
