<?php

namespace App\Modules\POS\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Payments\Services\PaymentMoney;
use App\Modules\POS\Models\PosCashMovement;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosSession;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryOperation;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PosCashMovementService
{
    /** @var array<int, true> */
    private static array $posting = [];

    public static function isPosting(int $movementId): bool
    {
        return isset(self::$posting[$movementId]);
    }

    public function post(
        User $actor,
        int $sessionId,
        string $movementType,
        mixed $amount,
        string $reason,
        string $idempotencyKey,
    ): PosCashMovement {
        Validator::make([
            'movement_type' => $movementType,
            'idempotency_key' => $idempotencyKey,
            'reason' => trim($reason),
        ], [
            'movement_type' => ['required', Rule::in(['cash_in', 'cash_out', 'cash_drop'])],
            'idempotency_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:191'],
        ])->validate();
        $reason = trim($reason);
        $amount = app(PaymentMoney::class)->normalize($amount, 'EGP');
        $direction = $movementType === 'cash_in' ? 'in' : 'out';
        $fingerprint = hash('sha256', json_encode([
            'session_id' => $sessionId,
            'movement_type' => $movementType,
            'direction' => $direction,
            'amount' => $amount,
            'reason' => $reason,
            'actor_id' => (int) $actor->id,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $sessionId, $movementType, $amount, $reason, $idempotencyKey, $direction, $fingerprint): PosCashMovement {
            ModuleRegistryLock::acquire();
            $initial = PosSession::query()->select(['id', 'location_id'])->whereKey($sessionId)->firstOrFail();
            app(PosAccess::class)->authorize($actor, PosPermissions::CASH_MOVEMENTS, (int) $initial->location_id);
            $session = PosSession::whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $device = PosDevice::whereKey($session->pos_device_id)->lockForUpdate()->firstOrFail();
            $existing = PosCashMovement::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->payload_fingerprint, $fingerprint)) {
                    $this->invalid('idempotency_key', __('pos.validation.cash_movement_key_conflict'));
                }
                $operation = TreasuryOperation::whereKey($existing->treasury_operation_id)->first();
                if ($operation === null || $operation->status !== 'posted') {
                    $this->invalid('cash_movement', __('pos.validation.cash_movement_treasury_evidence_missing'));
                }

                return $existing;
            }

            if ($session->status !== 'open' || $session->closed_at !== null
                || (int) $session->location_id !== (int) $device->location_id
                || $session->cash_treasury_account_id === null) {
                $this->invalid('session', __('pos.validation.cash_movement_requires_open_shift'));
            }
            if ((int) $actor->id !== (int) $session->opened_by
                && ! $actor->can(PosPermissions::SHIFTS_APPROVE)) {
                $this->invalid('session', __('pos.validation.cash_movement_authorization'));
            }
            if (! $device->is_active || $device->revoked_at !== null) {
                $this->invalid('device', __('pos.validation.cash_movement_active_device'));
            }
            $branch = Location::whereKey($session->location_id)->where('type', 'branch')->where('is_active', true)->exists();
            if (! $branch) {
                $this->invalid('session', __('pos.validation.cash_movement_active_branch'));
            }

            $account = TreasuryAccount::whereKey($session->cash_treasury_account_id)->lockForUpdate()->firstOrFail();
            if (! $account->is_active || (int) $account->location_id !== (int) $session->location_id
                || $account->ownership !== 'location' || $account->currency !== 'EGP'
                || ! in_array($account->type, ['branch_cashbox', 'location_cashbox'], true)) {
                $this->invalid('cash_treasury_account_id', __('pos.validation.cash_movement_drawer_unavailable'));
            }

            $movement = PosCashMovement::create([
                'reference_number' => 'POS-CASH-'.strtoupper(str_replace('-', '', (string) Str::uuid7())),
                'idempotency_key' => $idempotencyKey,
                'payload_fingerprint' => $fingerprint,
                'pos_session_id' => $session->id,
                'movement_type' => $movementType,
                'direction' => $direction,
                'amount' => $amount,
                'reason' => $reason,
                'created_by' => $actor->id,
                'occurred_at' => now(),
            ]);
            $treasury = app(TreasuryService::class);
            $operation = $treasury->createDraft($actor, [
                'type' => $direction === 'in' ? 'cash_deposit' : 'cash_withdrawal',
                'account_id' => (int) $account->id,
                'amount' => $amount,
                'currency' => 'EGP',
                'effective_date' => now()->toDateString(),
                'source_type' => 'external_other',
                'source_reference' => 'pos-cash-movement:'.$movement->uuid,
                'reason' => 'POS '.$movementType.': '.$reason,
            ], $idempotencyKey);
            self::$posting[(int) $movement->id] = true;
            try {
                $operation = $treasury->post($actor, $operation);
            } finally {
                unset(self::$posting[(int) $movement->id]);
            }
            $movement->fill(['treasury_operation_id' => $operation->id])->save();

            app(AuditLogger::class)->record('pos_cash_movement_posted', metadata: [
                'pos_cash_movement_id' => $movement->id,
                'pos_session_id' => $session->id,
                'treasury_operation_id' => $operation->id,
                'movement_type' => $movementType,
                'direction' => $direction,
                'amount' => $amount,
            ], actor: $actor);

            return $movement;
        }, 3);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
