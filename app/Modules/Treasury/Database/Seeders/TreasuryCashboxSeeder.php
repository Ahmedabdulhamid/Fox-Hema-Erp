<?php

namespace App\Modules\Treasury\Database\Seeders;

use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryAccess;
use App\Modules\Treasury\Services\TreasuryAccounts;
use App\Modules\Treasury\Services\TreasuryPermissions;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LogicException;

/** CLI initialization uses an explicitly selected real administrator and normal account authorization. */
abstract class TreasuryCashboxSeeder extends Seeder
{
    protected function initialize(Closure $callback): void
    {
        DB::transaction(function () use ($callback): void {
            ModuleRegistryLock::acquire();
            Validator::make(['seed_actor_id' => config('treasury.seed_actor_id')], [
                'seed_actor_id' => ['required', 'integer', 'min:1'],
            ], ['seed_actor_id.required' => 'Configure TREASURY_SEED_ACTOR_ID with an existing active global administrator.'])->validate();
            $actor = User::whereKey(config('treasury.seed_actor_id'))->where('is_active', true)->lockForUpdate()->first();
            if ($actor === null || ! $actor->isGlobalSuperAdmin()) {
                throw new LogicException('Treasury initialization requires an existing active global administrator; no users or permissions are created.');
            }
            if (! Module::where('code', 'treasury')->where('is_active', true)->exists()) {
                throw new LogicException('Register and explicitly activate Treasury before initializing its cashboxes.');
            }
            app(TreasuryAccess::class)->operation($actor, TreasuryPermissions::MANAGE_CASHBOXES, null);
            $callback($actor);
        }, 3);
    }

    /** @param array{code: string, name: string, type: string, ownership: string, location_id: ?int, currency: string, is_active: bool} $data */
    protected function ensureCashbox(User $actor, array $data): void
    {
        $identity = array_intersect_key($data, array_flip(['type', 'ownership', 'location_id', 'currency']));
        $reserved = TreasuryAccount::where('code', $data['code'])->lockForUpdate()->first();
        if ($reserved !== null) {
            foreach ($identity as $field => $value) {
                $stored = $reserved->getAttribute($field);
                if ($field === 'location_id' && $stored !== null) {
                    $stored = (int) $stored;
                }
                if ($stored !== $value) {
                    throw new LogicException('Reserved cashbox code '.$data['code'].' belongs to a different Treasury identity; reconcile it manually.');
                }
            }

            return;
        }

        $existing = TreasuryAccount::query();
        foreach ($identity as $field => $value) {
            $existing->where($field, $value);
        }
        if ($existing->orderBy('id')->lockForUpdate()->first() !== null) {
            return;
        }

        unset($data['code']);
        app(TreasuryAccounts::class)->save($actor, $data);
    }
}
