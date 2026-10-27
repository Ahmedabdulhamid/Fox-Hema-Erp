<?php

namespace App\Modules\Treasury\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryEntry;
use App\Modules\Treasury\Models\TreasuryOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TreasuryWorkspace
{
    public function __construct(private readonly TreasuryAccess $access) {}

    public function allows(?User $actor, string $permission, ?int $locationId = null): bool
    {
        if ($actor === null) {
            return false;
        }
        try {
            $this->access->operation($actor, $permission, $locationId);

            return true;
        } catch (AuthorizationException|HttpException $exception) {
            if ($exception instanceof HttpException && $exception->getStatusCode() !== 403) {
                throw $exception;
            }

            return false;
        }
    }

    public function accounts(User $actor): Builder
    {
        return $this->access->scope(TreasuryAccount::query(), $actor)
            ->where(fn (Builder $query) => $query->whereNull('location_id')
                ->orWhereHas('location', fn (Builder $query) => $query->where('is_active', true)));
    }

    public function accountsWithBalances(User $actor): Builder
    {
        $query = $this->accounts($actor);
        if (! $this->allows($actor, TreasuryPermissions::BALANCES, getPermissionsTeamId())) {
            return $query->select('treasury_accounts.*')->selectRaw('NULL as ledger_balance');
        }
        $balances = DB::table('treasury_entries as e')->join('treasury_operations as o', 'o.id', '=', 'e.operation_id')
            ->where('o.status', 'posted')->selectRaw("e.account_id, SUM(CASE WHEN e.direction = 'in' THEN e.amount ELSE -e.amount END) as ledger_balance")
            ->groupBy('e.account_id');

        return $query->leftJoinSub($balances, 'balances', 'balances.account_id', '=', 'treasury_accounts.id')
            ->select('treasury_accounts.*')->selectRaw('COALESCE(balances.ledger_balance, 0.0000) as ledger_balance');
    }

    /** Both sides must be visible: a transfer must not reveal another branch's account details. */
    public function operations(User $actor): Builder
    {
        $visible = $this->accounts($actor)->select('treasury_accounts.id');

        return TreasuryOperation::whereIn('account_id', clone $visible)
            ->where(fn (Builder $query) => $query->whereNull('destination_account_id')->orWhereIn('destination_account_id', clone $visible))
            ->with(['account.location', 'destinationAccount', 'reversal']);
    }

    /** A branch may see its own transfer leg while the other account remains restricted. */
    public function entries(User $actor): Builder
    {
        $visible = $this->accounts($actor)->select('treasury_accounts.id');
        $operations = $this->operations($actor)->select('treasury_operations.id');

        return TreasuryEntry::whereIn('account_id', $visible)
            ->whereHas('operation', fn (Builder $query) => $query->where('status', 'posted'))
            ->with(['account.location', 'operation'])
            ->withExists(['operation as can_view_operation' => fn (Builder $query) => $query->whereIn('treasury_operations.id', $operations)]);
    }

    public function authorizeOperation(User $actor, TreasuryOperation $operation, string $permission): void
    {
        $locations = RequestAuthorizationFacts::readFacts($this->ownershipKey($actor, (int) $operation->getKey()));
        if ($locations === null) {
            $ownership = $this->persistedOperationOwnership(TreasuryOperation::query()->whereKey($operation->getKey()));
            $locations = $ownership[$operation->getKey()] ?? throw (new ModelNotFoundException)->setModel(TreasuryOperation::class, [$operation->getKey()]);
        }
        foreach ($locations as $locationId) {
            $this->access->operation($actor, $permission, $locationId);
        }
    }

    /** @param list<int> $operationIds */
    public function primeOperationOwnership(User $actor, array $operationIds): void
    {
        $boundary = RequestAuthorizationFacts::readBoundary();
        if ($boundary === null || $operationIds === []) {
            return;
        }
        $missing = array_values(array_filter(array_unique($operationIds),
            fn (int $id): bool => RequestAuthorizationFacts::readFacts($this->ownershipKey($actor, $id)) === null));
        if ($missing === []) {
            return;
        }
        $ownership = $this->persistedOperationOwnership($this->operations($actor)->withoutEagerLoads()->whereKey($missing));
        if (RequestAuthorizationFacts::readBoundary() !== $boundary) {
            return;
        }
        foreach ($ownership as $id => $locations) {
            RequestAuthorizationFacts::readFacts($this->ownershipKey($actor, $id), fn (): array => $locations);
        }
    }

    private function ownershipKey(User $actor, int $operationId): string
    {
        return 'treasury-operation-ownership:'.json_encode([$actor->getMorphClass(), $actor->getKey(), $operationId], JSON_THROW_ON_ERROR);
    }

    /** @return array<int, list<int|null>> */
    private function persistedOperationOwnership(Builder $query): array
    {
        $rows = $query->join('treasury_accounts as source_ownership', 'source_ownership.id', '=', 'treasury_operations.account_id')
            ->leftJoin('treasury_accounts as destination_ownership', 'destination_ownership.id', '=', 'treasury_operations.destination_account_id')
            ->get(['treasury_operations.id', 'treasury_operations.destination_account_id',
                'source_ownership.location_id as source_location', 'destination_ownership.id as destination_id',
                'destination_ownership.location_id as destination_location']);
        $ownership = [];
        foreach ($rows as $row) {
            $locations = [$row->source_location === null ? null : (int) $row->source_location];
            if ($row->destination_account_id !== null) {
                if ($row->destination_id === null) {
                    throw (new ModelNotFoundException)->setModel(TreasuryAccount::class, [$row->destination_account_id]);
                }
                $locations[] = $row->destination_location === null ? null : (int) $row->destination_location;
            }
            $ownership[(int) $row->id] = $locations;
        }

        return $ownership;
    }

    public function canOperate(?User $actor, TreasuryOperation $operation, string $permission): bool
    {
        if ($actor === null) {
            return false;
        }
        try {
            $this->authorizeOperation($actor, $operation, $permission);

            return true;
        } catch (AuthorizationException|HttpException $exception) {
            if ($exception instanceof HttpException && $exception->getStatusCode() !== 403) {
                throw $exception;
            }

            return false;
        }
    }

    /** @return array<int, string> */
    public function accountOptions(User $actor): array
    {
        return $this->accounts($actor)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'currency'])
            ->mapWithKeys(fn (TreasuryAccount $account): array => [$account->id => $account->code.' | '.$account->name.' | '.$account->currency])->all();
    }
}
