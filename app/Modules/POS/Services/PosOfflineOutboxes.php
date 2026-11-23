<?php

namespace App\Modules\POS\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PosOfflineOutboxes
{
    /** @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function verify(User $actor, PosDevice $device, array $scope, string $outboxId): array
    {
        $this->validateIdentity($scope, $outboxId);

        return DB::transaction(function () use ($actor, $device, $scope, $outboxId): array {
            ModuleRegistryLock::acquire();
            $session = $this->session($actor, $device, $scope);
            $outbox = DB::table('pos_offline_outboxes')->where('uuid', $outboxId)
                ->where('pos_session_id', $session->id)->lockForUpdate()->first();
            abort_if($outbox === null, 409, 'This registered outbox is absent from the current server. Its saved evidence requires review.');

            return ['outbox_id' => $outboxId, 'status' => 'verified',
                'transaction_ids' => $outbox->transaction_ids === null ? null : json_decode($outbox->transaction_ids, true, flags: JSON_THROW_ON_ERROR)];
        }, 3);
    }

    /** @param array<string, mixed> $scope */
    public function register(User $actor, PosDevice $device, array $scope, string $outboxId): void
    {
        $this->validateIdentity($scope, $outboxId);
        DB::transaction(function () use ($actor, $device, $scope, $outboxId): void {
            ModuleRegistryLock::acquire();
            $session = $this->session($actor, $device, $scope);
            if ($session->status !== 'open' || $session->closing_started_at !== null || $session->closed_at !== null) {
                throw ValidationException::withMessages(['session' => __('pos.validation.session_not_open')]);
            }
            $existing = DB::table('pos_offline_outboxes')->where('uuid', $outboxId)->lockForUpdate()->first();
            if ($existing !== null) {
                abort_unless((int) $existing->pos_session_id === (int) $session->id, 403);

                return;
            }
            DB::table('pos_offline_outboxes')->insert([
                'uuid' => $outboxId, 'pos_session_id' => $session->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
    }

    /** Must be called inside the posting transaction, after locking its session. */
    public function authorizeSynchronization(int $sessionId, string $outboxId): void
    {
        $outbox = DB::table('pos_offline_outboxes')->where('uuid', $outboxId)
            ->where('pos_session_id', $sessionId)->lockForUpdate()->first();
        abort_if($outbox === null, 403);

    }

    public function authorizeLateSale(User $actor, PosSession $session, string $outboxId, ?string $occurredAt): void
    {
        $outbox = DB::table('pos_offline_outboxes')->where('uuid', $outboxId)
            ->where('pos_session_id', $session->id)->lockForUpdate()->first();
        abort_unless($outbox !== null && $occurredAt !== null && (int) $session->opened_by === (int) $actor->id
            && $session->status === 'closed' && $session->closed_at !== null, 409, 'The saved sale needs review before late synchronization.');
        $created = CarbonImmutable::parse($occurredAt);
        abort_unless($created->greaterThanOrEqualTo($session->opened_at)
            && $created->greaterThanOrEqualTo(CarbonImmutable::parse($outbox->created_at))
            && $created->lessThanOrEqualTo($session->closing_started_at ?? $session->closed_at), 409,
            'The saved sale was not created within its registered shift window.');
    }

    /** @param array<string, mixed> $scope */
    private function session(User $actor, PosDevice $device, array $scope): PosSession
    {
        app(PosAccess::class)->authorize($actor, PosPermissions::CHECKOUT, (int) $scope['branch_id']);
        abort_unless((int) $scope['device_id'] === (int) $device->id
            && (int) $scope['cashier_id'] === (int) $actor->id, 403);
        $session = PosSession::query()->whereKey($scope['session_id'])
            ->where('location_id', $scope['branch_id'])->where('pos_device_id', $device->id)
            ->where('opened_by', $actor->id)->lockForUpdate()->firstOrFail();
        $currentDevice = PosDevice::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
        abort_unless((int) $currentDevice->location_id === (int) $session->location_id
            && $currentDevice->is_active && $currentDevice->revoked_at === null, 403);

        return $session;
    }

    /** @param array<string, mixed> $scope */
    private function validateIdentity(array $scope, string $outboxId): void
    {
        Validator::make(['scope' => $scope, 'outbox_id' => $outboxId], [
            'outbox_id' => ['required', 'uuid', 'lowercase'],
            'scope' => ['required', 'array:branch_id,device_id,session_id,cashier_id'],
            'scope.branch_id' => ['required', 'integer', 'min:1'],
            'scope.device_id' => ['required', 'integer', 'min:1'],
            'scope.session_id' => ['required', 'integer', 'min:1'],
            'scope.cashier_id' => ['required', 'integer', 'min:1'],
        ])->validate();
    }
}
