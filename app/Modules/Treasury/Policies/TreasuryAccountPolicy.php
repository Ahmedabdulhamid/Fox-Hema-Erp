<?php

namespace App\Modules\Treasury\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryWorkspace;

class TreasuryAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return app(TreasuryWorkspace::class)->allows($user, TreasuryPermissions::VIEW, getPermissionsTeamId());
    }

    public function view(User $user, TreasuryAccount $account): bool
    {
        return app(TreasuryWorkspace::class)->allows($user, TreasuryPermissions::VIEW, $account->location_id);
    }

    public function create(User $user): bool
    {
        return app(TreasuryWorkspace::class)->allows($user, TreasuryPermissions::MANAGE_CASHBOXES, getPermissionsTeamId())
            || app(TreasuryWorkspace::class)->allows($user, TreasuryPermissions::MANAGE_BANKS, getPermissionsTeamId());
    }

    public function update(User $user, TreasuryAccount $account): bool
    {
        return app(TreasuryWorkspace::class)->allows($user, $account->type === 'bank'
            ? TreasuryPermissions::MANAGE_BANKS : TreasuryPermissions::MANAGE_CASHBOXES, $account->location_id);
    }

    public function delete(User $user, TreasuryAccount $account): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
