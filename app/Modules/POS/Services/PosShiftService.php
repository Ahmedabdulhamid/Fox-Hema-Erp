<?php

namespace App\Modules\POS\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosDevicePaymentMethod;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosSessionTender;
use App\Modules\Treasury\Models\TreasuryAccount;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PosShiftService
{
    /**
     * @return array<int, string>
     */
    public function cashDrawerOptions(User $actor, ?int $deviceId = null): array
    {
        $locationId = (int) getPermissionsTeamId();
        app(PosAccess::class)->authorize($actor, PosPermissions::SHIFTS_OPEN, $locationId);
        if ($deviceId === null || ! PosDevice::query()
            ->whereKey($deviceId)
            ->where('location_id', $locationId)
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->exists()) {
            return [];
        }

        return TreasuryAccount::query()
            ->where('location_id', $locationId)
            ->where('ownership', 'location')
            ->where('currency', 'EGP')
            ->whereIn('type', ['branch_cashbox', 'location_cashbox'])
            ->where('is_active', true)
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('pos_device_payment_methods')
                ->join('payment_methods', 'payment_methods.id', '=', 'pos_device_payment_methods.payment_method_id')
                ->whereColumn('pos_device_payment_methods.treasury_account_id', 'treasury_accounts.id')
                ->where('pos_device_payment_methods.pos_device_id', $deviceId)
                ->where('pos_device_payment_methods.is_active', true)
                ->where('payment_methods.type', 'cash')
                ->where('payment_methods.is_active', true)
                ->where('payment_methods.available_in_pos', true))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('pos_sessions')
                ->whereColumn('pos_sessions.cash_treasury_account_id', 'treasury_accounts.id')
                ->whereIn('pos_sessions.status', ['open', 'closing']))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function open(
        User $actor,
        int $deviceId,
        int $cashDrawerId,
        mixed $openingCash,
        string $idempotencyKey,
        ?string $notes = null,
    ): PosSession {
        Validator::make(['key' => $idempotencyKey], ['key' => ['required', 'uuid']])->validate();
        $openingCash = $this->nonNegativeAmount($openingCash, 'opening_cash');
        $notes = $notes === null ? null : trim($notes);
        Validator::make(['notes' => $notes], ['notes' => ['nullable', 'string', 'max:16000']])->validate();
        $notes = $notes === '' ? null : $notes;
        $fingerprint = $this->fingerprint([
            'device_id' => $deviceId,
            'cash_drawer_id' => $cashDrawerId,
            'opening_cash' => $openingCash,
            'currency' => 'EGP',
            'notes' => $notes,
            'actor_id' => (int) $actor->id,
        ]);

        try {
            return DB::transaction(function () use ($actor, $deviceId, $cashDrawerId, $openingCash, $idempotencyKey, $notes, $fingerprint): PosSession {
                ModuleRegistryLock::acquire();
                $device = PosDevice::whereKey($deviceId)->firstOrFail();
                $locationId = (int) $device->location_id;
                app(PosAccess::class)->authorize($actor, PosPermissions::SHIFTS_OPEN, $locationId);

                $existing = PosSession::where('opening_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    $this->assertFingerprint($existing->opening_payload_hash, $fingerprint);

                    return $existing;
                }

                $conflict = PosSession::query()
                    ->whereIn('status', ['open', 'closing'])
                    ->where(fn ($query) => $query
                        ->where('pos_device_id', $deviceId)
                        ->orWhere('opened_by', $actor->id)
                        ->orWhere('cash_treasury_account_id', $cashDrawerId))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
                if ($conflict !== null) {
                    $this->invalid('device_id', __('pos.validation.active_shift_conflict'));
                }

                $device = PosDevice::whereKey($deviceId)->lockForUpdate()->firstOrFail();
                if (! $device->is_active || $device->revoked_at !== null
                    || (int) $device->location_id !== $locationId
                    || (int) getPermissionsTeamId() !== $locationId) {
                    $this->invalid('device_id', __('pos.validation.invalid_opening_device'));
                }
                $branch = Location::whereKey($locationId)->where('type', 'branch')->where('is_active', true)->lockForUpdate()->first();
                if ($branch === null) {
                    $this->invalid('device_id', __('pos.validation.invalid_opening_branch'));
                }

                $account = TreasuryAccount::whereKey($cashDrawerId)->lockForUpdate()->firstOrFail();
                if (! $account->is_active || (int) $account->location_id !== $locationId
                    || $account->ownership !== 'location' || $account->currency !== 'EGP'
                    || ! in_array($account->type, ['branch_cashbox', 'location_cashbox'], true)) {
                    $this->invalid('cash_drawer_id', __('pos.validation.invalid_cash_drawer'));
                }

                $cashMappingExists = PosDevicePaymentMethod::query()
                    ->where('pos_device_id', $device->id)
                    ->where('treasury_account_id', $account->id)
                    ->where('is_active', true)
                    ->whereHas('paymentMethod', fn ($query) => $query
                        ->where('type', 'cash')->where('is_active', true)->where('available_in_pos', true))
                    ->exists();
                if (! $cashMappingExists) {
                    $this->invalid('cash_drawer_id', __('pos.validation.cash_mapping_missing'));
                }

                $entries = DB::table('treasury_entries')
                    ->join('treasury_operations', 'treasury_operations.id', '=', 'treasury_entries.operation_id')
                    ->where('treasury_entries.account_id', $account->id)
                    ->where('treasury_operations.status', 'posted')
                    ->orderBy('treasury_entries.id')
                    ->select(['treasury_entries.direction', 'treasury_entries.amount'])
                    ->lockForUpdate()->get();
                $drawerBalance = BigDecimal::zero()->toScale(4);
                foreach ($entries as $entry) {
                    $drawerBalance = $entry->direction === 'in'
                        ? $drawerBalance->plus($entry->amount)
                        : $drawerBalance->minus($entry->amount);
                }
                if (! BigDecimal::of($openingCash)->isEqualTo($drawerBalance)) {
                    $this->invalid('opening_cash', __('pos.validation.opening_cash_mismatch', ['balance' => (string) $drawerBalance]));
                }

                $sessionUuid = (string) Str::uuid7();
                $sessionNumber = app(DocumentNumberService::class)->allocate($locationId, 'SH', $sessionUuid);
                $session = new PosSession;
                $session->uuid = $sessionUuid;
                $session->fill([
                    'session_number' => $sessionNumber,
                    'pos_device_id' => $device->id,
                    'location_id' => $locationId,
                    'opened_by' => $actor->id,
                    'status' => 'open',
                    'opened_at' => now(),
                    'opening_cash' => $openingCash,
                    'cash_treasury_account_id' => $account->id,
                    'currency' => 'EGP',
                    'opening_key' => $idempotencyKey,
                    'opening_payload_hash' => $fingerprint,
                    'notes' => $notes,
                ]);
                $session->save();
                app(AuditLogger::class)->record('pos_shift_opened', metadata: [
                    'pos_session_id' => $session->id,
                    'pos_device_id' => $device->id,
                    'location_id' => $locationId,
                    'cash_treasury_account_id' => $account->id,
                    'opening_cash' => $openingCash,
                ], actor: $actor);

                return $session;
            }, 3);
        } catch (QueryException $exception) {
            $existing = PosSession::where('opening_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $this->assertFingerprint($existing->opening_payload_hash, $fingerprint);

                return $existing;
            }

            if ($this->isUniqueConstraintViolation($exception)) {
                throw ValidationException::withMessages([
                    'device_id' => __('pos.validation.active_shift_conflict'),
                ]);
            }

            throw $exception;
        }
    }

    /**
     * @param  array{counted_cash: int|string, tender_counts: array<int|string, int|string>, difference_reason?: ?string, notes?: ?string}  $data
     */
    public function close(User $actor, int $sessionId, array $data, string $idempotencyKey): PosSession
    {
        Validator::make(['key' => $idempotencyKey], ['key' => ['required', 'uuid']])->validate();
        $data['tender_counts'] ??= [];
        $this->validateClosePayload($data);
        $countedCash = $this->nonNegativeAmount($data['counted_cash'], 'counted_cash');
        $tenderCounts = $this->normalizeTenderCounts($data['tender_counts']);
        $differenceReason = isset($data['difference_reason']) ? trim((string) $data['difference_reason']) : null;
        $notes = isset($data['notes']) ? trim((string) $data['notes']) : null;
        $fingerprint = $this->fingerprint([
            'session_id' => $sessionId,
            'counted_cash' => $countedCash,
            'tender_counts' => $tenderCounts,
            'difference_reason' => $differenceReason,
            'notes' => $notes,
            'actor_id' => (int) $actor->id,
        ]);

        return DB::transaction(function () use ($actor, $sessionId, $idempotencyKey, $fingerprint, $countedCash, $tenderCounts, $differenceReason, $notes): PosSession {
            ModuleRegistryLock::acquire();
            $initial = PosSession::query()->select(['id', 'location_id'])->whereKey($sessionId)->firstOrFail();
            app(PosAccess::class)->authorize($actor, PosPermissions::SHIFTS_CLOSE, (int) $initial->location_id);
            $session = PosSession::whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $device = PosDevice::whereKey($session->pos_device_id)->lockForUpdate()->firstOrFail();
            $this->assertSessionContext($session, $device);
            $otherSessionHasKey = PosSession::query()
                ->where('closing_key', $idempotencyKey)
                ->where('id', '!=', $session->id)
                ->exists();
            if ($otherSessionHasKey) {
                $this->invalid('idempotency_key', __('pos.validation.invalid_closing_key'));
            }

            if ($session->closed_at !== null || $session->status === 'closed') {
                if ($session->closing_key !== $idempotencyKey) {
                    $this->invalid('session', __('pos.validation.closing_already_done'));
                }
                $this->assertFingerprint($session->closing_payload_hash, $fingerprint);

                return $session;
            }
            if (! in_array($session->status, ['open', 'closing'], true)) {
                $this->invalid('session', __('pos.validation.invalid_closing_state'));
            }
            if ($actor->id !== $session->opened_by && ! app(PosAccess::class)->can($actor, PosPermissions::SHIFTS_APPROVE)) {
                $this->invalid('session', __('pos.validation.close_permission'));
            }
            if ($session->closing_key !== null && $session->closing_key !== $idempotencyKey) {
                $this->invalid('idempotency_key', __('pos.validation.closing_conflict'));
            }
            if ($session->expected_cash !== null) {
                $this->assertFingerprint($session->closing_payload_hash, $fingerprint);
            }
            if ($session->status === 'open' || $session->closing_key === null) {
                $session->fill([
                    'status' => 'closing',
                    'closing_started_at' => now(),
                    'closing_key' => $idempotencyKey,
                    'closed_by' => $actor->id,
                ])->save();
            }

            if ($this->hasIncompleteTransactions($session)) {
                app(AuditLogger::class)->record('pos_shift_closing_waiting_for_transactions', metadata: [
                    'pos_session_id' => $session->id,
                ], actor: $actor);

                return $session;
            }

            $drawer = TreasuryAccount::whereKey($session->cash_treasury_account_id)->lockForUpdate()->firstOrFail();
            $this->validateCashDrawer($drawer, (int) $session->location_id);
            $tenders = $this->expectedTenderTotals((int) $session->id);
            $this->validateTenderCounts($tenders, $tenderCounts);
            $expectedCash = $this->expectedCash($session, $tenders);
            $cashDifference = BigDecimal::of($countedCash)->minus($expectedCash)->toScale(4);
            $hasDifference = ! $cashDifference->isZero();
            $tenderRows = [];

            foreach ($tenders as $methodId => $tender) {
                $verifiedAmount = $tenderCounts[$methodId];
                $difference = BigDecimal::of($verifiedAmount)->minus($tender['expected_amount'])->toScale(4);
                if (! $difference->isZero()) {
                    $hasDifference = true;
                }
                $tenderRows[] = [
                    'payment_method_id' => $methodId,
                    'method' => $tender['code'],
                    'expected_amount' => $tender['expected_amount'],
                    'verified_amount' => $verifiedAmount,
                    'difference_amount' => (string) $difference,
                    'verified_by' => $actor->id,
                    'verified_at' => now(),
                ];
            }
            if ($hasDifference && ($differenceReason === null || $differenceReason === '')) {
                $this->invalid('difference_reason', __('pos.validation.discrepancy_reason_required'));
            }

            foreach ($tenderRows as $tender) {
                PosSessionTender::updateOrCreate(
                    ['pos_session_id' => $session->id, 'payment_method_id' => $tender['payment_method_id']],
                    $tender,
                );
            }
            $session->fill([
                'closing_payload_hash' => $fingerprint,
                'closed_by' => $actor->id,
                'notes' => $notes ?? $session->notes,
                'expected_cash' => $expectedCash,
                'counted_cash' => $countedCash,
                'cash_difference' => (string) $cashDifference,
                'cash_difference_reason' => $differenceReason,
            ])->save();

            if ($hasDifference && ! $this->hasApprovalPermission($actor, (int) $session->location_id)) {
                app(AuditLogger::class)->record('pos_shift_closing_pending_approval', metadata: [
                    'pos_session_id' => $session->id,
                    'cash_difference' => (string) $cashDifference,
                ], actor: $actor);

                return $session;
            }

            if ($hasDifference) {
                $session->fill([
                    'closing_approved_by' => $actor->id,
                    'closing_approved_at' => now(),
                ])->save();
            }

            return $this->finalizeClose($actor, $session);
        }, 3);
    }

    public function approveClosing(User $actor, int $sessionId, string $reason): PosSession
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:16000']])->validate();

        return DB::transaction(function () use ($actor, $sessionId, $reason): PosSession {
            ModuleRegistryLock::acquire();
            $initial = PosSession::query()->select(['id', 'location_id'])->whereKey($sessionId)->firstOrFail();
            app(PosAccess::class)->authorize($actor, PosPermissions::SHIFTS_APPROVE, (int) $initial->location_id);
            $session = PosSession::whereKey($sessionId)->lockForUpdate()->firstOrFail();
            if ($session->status === 'closed') {
                if ((int) $session->closing_approved_by === (int) $actor->id) {
                    return $session;
                }
                $this->invalid('session', __('pos.validation.closing_already_done'));
            }
            if ($session->status !== 'closing' || $session->closing_key === null
                || $session->expected_cash === null || $session->counted_cash === null) {
                $this->invalid('session', __('pos.validation.approval_requires_reconciliation'));
            }
            $device = PosDevice::whereKey($session->pos_device_id)->lockForUpdate()->firstOrFail();
            $this->assertSessionContext($session, $device);

            if ($this->hasIncompleteTransactions($session)) {
                $this->invalid('session', __('pos.validation.incomplete_transactions'));
            }
            $session->fill([
                'closing_approved_by' => $actor->id,
                'closing_approved_at' => now(),
            ])->save();
            app(AuditLogger::class)->record('pos_shift_closing_approved', metadata: [
                'pos_session_id' => $session->id,
                'approval_reason' => $reason,
                'cash_difference' => $session->cash_difference,
            ], actor: $actor);

            return $this->finalizeClose($actor, $session);
        }, 3);
    }

    /**
     * @return list<array{payment_method_id: int, code: string, name: string, expected_amount: string}>
     */
    public function expectedTenders(User $actor, int $sessionId): array
    {
        $session = PosSession::query()->select(['id', 'location_id'])->whereKey($sessionId)->firstOrFail();
        app(PosAccess::class)->authorize($actor, PosPermissions::VIEW, (int) $session->location_id);

        return collect($this->expectedTenderTotals($sessionId))
            ->map(fn (array $tender, int $methodId): array => [
                'payment_method_id' => $methodId,
                'code' => $tender['code'],
                'name' => $tender['name'],
                'expected_amount' => $tender['expected_amount'],
            ])
            ->values()
            ->all();
    }

    public function expectedClosingCash(User $actor, int $sessionId): string
    {
        $session = PosSession::query()->findOrFail($sessionId);
        app(PosAccess::class)->authorize($actor, PosPermissions::VIEW, (int) $session->location_id);

        return $this->expectedCash($session, $this->expectedTenderTotals($sessionId));
    }

    /** @return array{late_sales: int, late_amount: string, current_expected_cash: ?string, cash_difference: ?string, tender_differences: array<int, string>, requires_review: bool} */
    public function lateReconciliation(User $actor, int $sessionId): array
    {
        $session = PosSession::findOrFail($sessionId);
        app(PosAccess::class)->authorize($actor, PosPermissions::VIEW, (int) $session->location_id);
        $late = $session->transactions()->where('sync_status', 'completed')->where('result_payload->late_synchronization', true)->get();
        $amount = BigDecimal::zero()->toScale(4);
        foreach ($late as $transaction) {
            $amount = $amount->plus($transaction->result_payload['total_amount']);
        }
        $tenders = $this->expectedTenderTotals($sessionId);
        $expected = $session->status === 'closed' ? $this->expectedCash($session, $tenders) : null;
        $difference = $expected === null ? null : (string) BigDecimal::of($session->counted_cash)->minus($expected)->toScale(4);
        $counts = $session->tenders()->pluck('verified_amount', 'payment_method_id');
        $tenderDifferences = [];
        foreach ($tenders as $methodId => $tender) {
            if ($tender['type'] === 'cash' && ! $counts->has($methodId)) {
                continue;
            }
            $tenderDifferences[$methodId] = (string) BigDecimal::of($counts[$methodId] ?? '0')->minus($tender['expected_amount'])->toScale(4);
        }

        return ['late_sales' => $late->count(), 'late_amount' => (string) $amount, 'current_expected_cash' => $expected,
            'cash_difference' => $difference, 'tender_differences' => $tenderDifferences,
            'requires_review' => $late->isNotEmpty() && (($difference !== null && ! BigDecimal::of($difference)->isZero())
                || collect($tenderDifferences)->contains(fn (string $value): bool => ! BigDecimal::of($value)->isZero()))];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateClosePayload(array $data): void
    {
        $allowed = ['counted_cash', 'tender_counts', 'difference_reason', 'notes'];
        if (array_diff(array_keys($data), $allowed) !== []
            || ! array_key_exists('counted_cash', $data)
            || ! array_key_exists('tender_counts', $data)
            || ! is_array($data['tender_counts'])) {
            throw ValidationException::withMessages(['closing' => __('pos.validation.closing_counts_required')]);
        }
        Validator::make($data, [
            'difference_reason' => ['nullable', 'string', 'max:16000'],
            'notes' => ['nullable', 'string', 'max:16000'],
        ])->validate();
    }

    /**
     * @param  array<int|string, int|string>  $counts
     * @return array<int, string>
     */
    private function normalizeTenderCounts(array $counts): array
    {
        $normalized = [];
        foreach ($counts as $methodId => $amount) {
            if (! ctype_digit((string) $methodId) || (int) $methodId < 1) {
                $this->invalid('tender_counts', __('pos.validation.tender_count_keys'));
            }
            $normalized[(int) $methodId] = $this->signedAmount($amount, 'tender_counts.'.$methodId);
        }
        ksort($normalized);

        return $normalized;
    }

    /**
     * @return array<int, array{code: string, name: string, type: string, expected_amount: string}>
     */
    private function expectedTenderTotals(int $sessionId): array
    {
        $totals = [];
        $inconsistentReceipt = DB::table('payments as payments')
            ->join('payment_methods as methods', 'methods.id', '=', 'payments.payment_method_id')
            ->leftJoin('treasury_operations as operations', 'operations.payment_id', '=', 'payments.id')
            ->where('payments.pos_session_id', $sessionId)
            ->where('payments.status', 'posted')
            ->where('payments.direction', 'in')
            ->where(fn ($query) => $query
                ->where(fn ($accounted) => $accounted
                    ->whereNotNull('payments.treasury_account_id')
                    ->where(fn ($evidence) => $evidence
                        ->whereNull('operations.id')
                        ->orWhere('operations.status', '!=', 'posted')
                        ->orWhereColumn('operations.account_id', '!=', 'payments.treasury_account_id')
                        ->orWhereColumn('operations.amount', '!=', 'payments.amount')
                        ->orWhere('operations.type', '!=', 'payment_receipt')
                        ->orWhereNotExists(fn ($entries) => $entries->selectRaw('1')
                            ->from('treasury_entries')
                            ->whereColumn('treasury_entries.operation_id', 'operations.id')
                            ->whereColumn('treasury_entries.account_id', 'payments.treasury_account_id')
                            ->whereColumn('treasury_entries.amount', 'payments.amount')
                            ->where('treasury_entries.direction', 'in'))))
                ->orWhere(fn ($accountless) => $accountless
                    ->whereNull('payments.treasury_account_id')
                    ->where(fn ($evidence) => $evidence
                        ->where('methods.type', 'cash')
                        ->orWhereNotNull('operations.id'))))
            ->exists();
        $inconsistentRefund = DB::table('payment_refunds as refunds')
            ->join('payment_methods as methods', 'methods.id', '=', 'refunds.payment_method_id')
            ->leftJoin('treasury_accounts as accounts', 'accounts.id', '=', 'refunds.treasury_account_id')
            ->leftJoin('treasury_operations as operations', 'operations.id', '=', 'refunds.treasury_operation_id')
            ->where('refunds.pos_session_id', $sessionId)
            ->where('refunds.status', 'posted')
            ->where(fn ($query) => $query
                ->where(fn ($accounted) => $accounted
                    ->whereNotNull('refunds.treasury_account_id')
                    ->where(fn ($evidence) => $evidence
                        ->whereNull('operations.id')
                        ->orWhere('operations.status', '!=', 'posted')
                        ->orWhereColumn('operations.account_id', '!=', 'refunds.treasury_account_id')
                        ->orWhereColumn('operations.amount', '!=', 'refunds.amount')
                        ->orWhere('operations.type', '!=', DB::raw("CASE WHEN accounts.type = 'bank' THEN 'bank_withdrawal' ELSE 'cash_withdrawal' END"))
                        ->orWhereNotExists(fn ($entries) => $entries->selectRaw('1')
                            ->from('treasury_entries')
                            ->whereColumn('treasury_entries.operation_id', 'operations.id')
                            ->whereColumn('treasury_entries.account_id', 'refunds.treasury_account_id')
                            ->whereColumn('treasury_entries.amount', 'refunds.amount')
                            ->where('treasury_entries.direction', 'out'))))
                ->orWhere(fn ($accountless) => $accountless
                    ->whereNull('refunds.treasury_account_id')
                    ->where(fn ($evidence) => $evidence
                        ->where('methods.type', 'cash')
                        ->orWhereNotNull('operations.id'))))
            ->exists();
        if ($inconsistentReceipt || $inconsistentRefund) {
            $this->invalid('tender_counts', __('pos.validation.tender_evidence_mismatch'));
        }

        $receipts = DB::table('payments')
            ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->leftJoin('treasury_operations as receipt_operations', function ($join): void {
                $join->on('receipt_operations.payment_id', '=', 'payments.id')
                    ->where('receipt_operations.status', 'posted');
            })
            ->where('payments.pos_session_id', $sessionId)
            ->where('payments.status', 'posted')
            ->where('payments.direction', 'in')
            ->where(fn ($query) => $query
                ->whereNotNull('receipt_operations.id')
                ->orWhere(fn ($accountless) => $accountless
                    ->whereNull('payments.treasury_account_id')
                    ->where('payment_methods.type', '!=', 'cash')))
            ->selectRaw('payment_methods.id, payment_methods.code, payment_methods.name, payment_methods.type, SUM(payments.amount) AS amount')
            ->groupBy('payment_methods.id', 'payment_methods.code', 'payment_methods.name', 'payment_methods.type')
            ->get();
        foreach ($receipts as $receipt) {
            $totals[(int) $receipt->id] = [
                'code' => $receipt->code,
                'name' => $receipt->name,
                'type' => $receipt->type,
                'expected_amount' => BigDecimal::of($receipt->amount)->toScale(4),
            ];
        }

        $refunds = DB::table('payment_refunds')
            ->join('payment_methods', 'payment_methods.id', '=', 'payment_refunds.payment_method_id')
            ->leftJoin('treasury_operations as refund_operations', function ($join): void {
                $join->on('refund_operations.id', '=', 'payment_refunds.treasury_operation_id')
                    ->where('refund_operations.status', 'posted');
            })
            ->where('payment_refunds.pos_session_id', $sessionId)
            ->where('payment_refunds.status', 'posted')
            ->whereNull('payment_refunds.recovered_at')
            ->where(fn ($query) => $query
                ->whereNotNull('refund_operations.id')
                ->orWhere(fn ($accountless) => $accountless
                    ->whereNull('payment_refunds.treasury_account_id')
                    ->where('payment_methods.type', '!=', 'cash')))
            ->selectRaw('payment_methods.id, payment_methods.code, payment_methods.name, payment_methods.type, SUM(payment_refunds.amount) AS amount')
            ->groupBy('payment_methods.id', 'payment_methods.code', 'payment_methods.name', 'payment_methods.type')
            ->get();
        foreach ($refunds as $refund) {
            $methodId = (int) $refund->id;
            $totals[$methodId] ??= [
                'code' => $refund->code,
                'name' => $refund->name,
                'type' => $refund->type,
                'expected_amount' => BigDecimal::zero()->toScale(4),
            ];
            $totals[$methodId]['expected_amount'] = $totals[$methodId]['expected_amount']
                ->minus($refund->amount)->toScale(4);
        }

        ksort($totals);
        foreach ($totals as $methodId => $total) {
            $totals[$methodId]['expected_amount'] = (string) $total['expected_amount'];
        }

        return $totals;
    }

    /**
     * @param  array<int, array{code: string, name: string, type: string, expected_amount: string}>  $tenders
     * @param  array<int, string>  $counts
     */
    private function validateTenderCounts(array $tenders, array $counts): void
    {
        $requiredIds = array_keys($tenders);
        if (array_diff($requiredIds, array_keys($counts)) !== [] || array_diff(array_keys($counts), $requiredIds) !== []) {
            $this->invalid('tender_counts', __('pos.validation.tender_counts_required'));
        }
    }

    /**
     * @param  array<int, array{code: string, name: string, type: string, expected_amount: string}>  $tenders
     */
    private function expectedCash(PosSession $session, array $tenders): string
    {
        $cashTenders = BigDecimal::zero()->toScale(4);
        foreach ($tenders as $tender) {
            if ($tender['type'] === 'cash') {
                $cashTenders = $cashTenders->plus($tender['expected_amount'])->toScale(4);
            }
        }
        $inconsistentMovement = DB::table('pos_cash_movements as movements')
            ->join('treasury_operations as operations', 'operations.id', '=', 'movements.treasury_operation_id')
            ->where('movements.pos_session_id', $session->id)
            ->where(function ($query) use ($session): void {
                $query->where('operations.status', '!=', 'posted')
                    ->orWhere('operations.account_id', '!=', $session->cash_treasury_account_id)
                    ->orWhere('operations.amount', '!=', DB::raw('movements.amount'))
                    ->orWhere('operations.type', '!=', DB::raw("CASE WHEN movements.direction = 'in' THEN 'cash_deposit' ELSE 'cash_withdrawal' END"))
                    ->orWhereNotExists(fn ($entries) => $entries->selectRaw('1')
                        ->from('treasury_entries')
                        ->whereColumn('treasury_entries.operation_id', 'operations.id')
                        ->where('treasury_entries.account_id', $session->cash_treasury_account_id)
                        ->whereColumn('treasury_entries.amount', 'movements.amount')
                        ->whereColumn('treasury_entries.direction', 'movements.direction'));
            })
            ->exists();
        if ($inconsistentMovement) {
            $this->invalid('cash_movement', __('pos.validation.cash_movement_evidence_mismatch'));
        }
        $movement = DB::table('pos_cash_movements as movements')
            ->join('treasury_operations as operations', 'operations.id', '=', 'movements.treasury_operation_id')
            ->join('treasury_entries as entries', function ($join) use ($session): void {
                $join->on('entries.operation_id', '=', 'operations.id')
                    ->where('entries.account_id', '=', $session->cash_treasury_account_id);
            })
            ->where('movements.pos_session_id', $session->id)
            ->where('operations.status', 'posted')
            ->selectRaw("COALESCE(SUM(CASE WHEN entries.direction = 'in' THEN entries.amount ELSE -entries.amount END), 0.0000) AS net_amount")
            ->value('net_amount');
        $expected = BigDecimal::of($session->opening_cash)
            ->plus($cashTenders)
            ->plus($movement ?? '0.0000')
            ->toScale(4);
        if ($expected->isNegative() || $expected->isGreaterThanOrEqualTo('10000000000000000')) {
            $this->invalid('expected_cash', __('pos.validation.expected_cash_range'));
        }

        return (string) $expected;
    }

    private function hasIncompleteTransactions(PosSession $session): bool
    {
        return $session->transactions()->whereIn('sync_status', ['received', 'processing'])->exists();
    }

    private function assertSessionContext(PosSession $session, PosDevice $device): void
    {
        if ((int) $session->pos_device_id !== (int) $device->id
            || (int) $session->location_id !== (int) $device->location_id
            || $session->cash_treasury_account_id === null) {
            $this->invalid('session', __('pos.validation.inconsistent_shift'));
        }
    }

    private function validateCashDrawer(TreasuryAccount $account, int $locationId): void
    {
        if (! $account->is_active || (int) $account->location_id !== $locationId
            || $account->ownership !== 'location' || $account->currency !== 'EGP'
            || ! in_array($account->type, ['branch_cashbox', 'location_cashbox'], true)) {
            $this->invalid('cash_drawer_id', __('pos.validation.cash_drawer_unavailable'));
        }
    }

    private function hasApprovalPermission(User $actor, int $locationId): bool
    {
        if (! app(PosAccess::class)->can($actor, PosPermissions::SHIFTS_APPROVE)) {
            return false;
        }
        app(PosAccess::class)->authorize($actor, PosPermissions::SHIFTS_APPROVE, $locationId);

        return true;
    }

    private function finalizeClose(User $actor, PosSession $session): PosSession
    {
        $session->fill([
            'status' => 'closed',
            'closed_at' => now(),
        ])->save();
        app(AuditLogger::class)->record('pos_shift_closed', metadata: [
            'pos_session_id' => $session->id,
            'expected_cash' => $session->expected_cash,
            'counted_cash' => $session->counted_cash,
            'cash_difference' => $session->cash_difference,
        ], actor: $actor);

        return $session;
    }

    private function nonNegativeAmount(mixed $amount, string $field): string
    {
        if ((! is_string($amount) && ! is_int($amount)) || ! preg_match('/^\d+(?:\.\d+)?$/D', (string) $amount)) {
            $this->invalid($field, __('pos.validation.amount_invalid'));
        }
        try {
            $normalized = BigDecimal::of((string) $amount)->toScale(4, RoundingMode::Unnecessary);
        } catch (MathException) {
            $this->invalid($field, __('pos.validation.amount_precision'));
        }
        if ($normalized->isGreaterThanOrEqualTo('10000000000000000')) {
            $this->invalid($field, __('pos.validation.amount_range'));
        }

        return (string) $normalized;
    }

    private function signedAmount(mixed $amount, string $field): string
    {
        if ((! is_string($amount) && ! is_int($amount)) || ! preg_match('/^-?\d+(?:\.\d+)?$/D', (string) $amount)) {
            $this->invalid($field, __('pos.validation.tender_amount_invalid'));
        }
        try {
            $normalized = BigDecimal::of((string) $amount)->toScale(4, RoundingMode::Unnecessary);
        } catch (MathException) {
            $this->invalid($field, __('pos.validation.amount_precision'));
        }
        if ($normalized->isGreaterThanOrEqualTo('10000000000000000')
            || $normalized->isLessThanOrEqualTo('-10000000000000000')) {
            $this->invalid($field, __('pos.validation.amount_range'));
        }

        return (string) $normalized;
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function assertFingerprint(?string $stored, string $expected): void
    {
        if ($stored === null || ! hash_equals($stored, $expected)) {
            $this->invalid('idempotency_key', __('pos.validation.invalid_closing_key'));
        }
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23000'
            && in_array((int) ($exception->errorInfo[1] ?? 0), [1062, 1555], true);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
