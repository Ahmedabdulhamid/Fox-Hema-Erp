<?php

namespace App\Modules\Treasury\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Treasury\Models\TreasuryAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TreasuryAccounts
{
    /** @var array<int, true> */
    private static array $writing = [];

    public function __construct(private readonly TreasuryAccess $access) {}

    public static function isWriting(int $id): bool
    {
        return isset(self::$writing[$id]);
    }

    /** @param array{name?: string, type?: string, ownership?: string, location_id?: ?int, currency?: string, is_active?: bool, bank_name?: ?string, account_identifier?: ?string} $data */
    public function save(User $actor, array $data, ?TreasuryAccount $account = null): TreasuryAccount
    {
        return DB::transaction(function () use ($actor, $data, $account): TreasuryAccount {
            ModuleRegistryLock::acquire();
            abort_unless(Module::where('code', 'access_management')->exists(), 403);
            $account = $account === null ? null : TreasuryAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $before = $account?->only(['name', 'is_active', 'bank_name', 'account_identifier']);
            $allowed = $account === null
                ? ['name', 'type', 'ownership', 'location_id', 'currency', 'is_active', 'bank_name', 'account_identifier']
                : ['name', 'is_active', 'bank_name', 'account_identifier'];
            if (array_diff(array_keys($data), $allowed) !== []) {
                throw ValidationException::withMessages(['account' => 'Unsupported fields; account identity and ownership cannot be changed.']);
            }
            $values = array_replace($account?->only(['name', 'type', 'ownership', 'location_id', 'currency', 'is_active', 'bank_name', 'account_identifier'])
                ?? ['currency' => 'EGP', 'location_id' => null, 'is_active' => true, 'bank_name' => null, 'account_identifier' => null], $data);
            Validator::make($values, [
                'name' => ['required', 'string', 'max:191'],
                'type' => ['required', Rule::in(['branch_cashbox', 'central_cashbox', 'location_cashbox', 'bank'])],
                'ownership' => ['required', Rule::in(['location', 'central'])],
                'location_id' => ['nullable', 'integer'],
                'currency' => ['required', Rule::in(['EGP'])], 'is_active' => ['required', 'boolean'],
                'bank_name' => ['nullable', 'string', 'max:120'], 'account_identifier' => ['nullable', 'string', 'max:120'],
            ])->validate();
            $locationId = $values['location_id'] === null ? null : (int) $values['location_id'];
            $values['location_id'] = $locationId;
            if (($values['ownership'] === 'central') !== ($locationId === null)
                || ($values['type'] === 'central_cashbox' && $values['ownership'] !== 'central')
                || (in_array($values['type'], ['branch_cashbox', 'location_cashbox'], true) && $locationId === null)
                || ($values['type'] !== 'bank' && ($values['bank_name'] !== null || $values['account_identifier'] !== null))) {
                throw ValidationException::withMessages(['ownership' => 'Select explicit central ownership or an active operational location; bank details belong only to bank accounts.']);
            }
            $this->access->operation($actor, $values['type'] === 'bank'
                ? TreasuryPermissions::MANAGE_BANKS : TreasuryPermissions::MANAGE_CASHBOXES, $locationId);
            if ($locationId !== null) {
                $location = Location::whereKey($locationId)->lockForUpdate()->firstOrFail();
                if (! $location->is_active || ($values['type'] === 'branch_cashbox' && $location->type !== 'branch')) {
                    throw ValidationException::withMessages(['location_id' => 'Branch cashboxes require an active branch.']);
                }
            }
            if ($account === null) {
                $account = TreasuryAccount::create($values + ['created_by' => $actor->id, 'updated_by' => $actor->id]);
            } else {
                self::$writing[$account->id] = true;
                try {
                    $account->fill($values + ['updated_by' => $actor->id])->save();
                } finally {
                    unset(self::$writing[$account->id]);
                }
            }
            app(AuditLogger::class)->record('treasury_account_saved', metadata: [
                'account_id' => $account->id, 'ownership' => $account->ownership, 'location_id' => $locationId,
                'is_active' => $account->is_active, 'changes' => array_keys($data),
                'before' => $before, 'after' => $account->only(['name', 'is_active', 'bank_name', 'account_identifier']),
            ], actor: $actor);

            return $account->refresh();
        }, 3);
    }

    public function visible(User $actor): Collection
    {
        return $this->access->scope(TreasuryAccount::query(), $actor)->orderBy('code')->get();
    }
}
