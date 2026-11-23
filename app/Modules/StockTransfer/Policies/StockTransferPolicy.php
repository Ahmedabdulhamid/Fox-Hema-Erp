<?php

namespace App\Modules\StockTransfer\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\StockTransfer\Models\StockTransfer;
use App\Modules\StockTransfer\Services\StockTransferAccess;
use App\Modules\StockTransfer\Services\StockTransferPermissions;

class StockTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return app(StockTransferAccess::class)->allows($user, StockTransferPermissions::VIEW);
    }

    public function view(User $user, StockTransfer $record): bool
    {
        return app(StockTransferAccess::class)->allows($user, StockTransferPermissions::VIEW, (int) $record->from_location_id)
            && app(StockTransferAccess::class)->allows($user, StockTransferPermissions::VIEW, (int) $record->to_location_id);
    }

    public function create(User $user): bool
    {
        return app(StockTransferAccess::class)->allows($user, StockTransferPermissions::MANAGE);
    }

    public function update(User $user, StockTransfer $record): bool
    {
        return $record->status === 'draft' && $record->requested_at === null
            && app(StockTransferAccess::class)->allows($user, StockTransferPermissions::MANAGE, (int) $record->from_location_id)
            && app(StockTransferAccess::class)->allows($user, StockTransferPermissions::MANAGE, (int) $record->to_location_id);
    }

    public function delete(User $user, StockTransfer $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
