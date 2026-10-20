<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use Illuminate\Support\Facades\DB;

class UserLocationManager
{
    public function canManage(User $user, Location $location): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can('update', $user)
            && ($actor->isGlobalSuperAdmin() || $actor->locations()->whereKey($location)->exists());
    }

    public function attach(User $user, Location $location): void
    {
        abort_unless($this->canManage($user, $location) && $location->is_active, 403);

        DB::transaction(function () use ($user, $location): void {
            $changes = $user->locations()->syncWithoutDetaching([$location->getKey()]);
            if ($changes['attached'] !== []) {
                app(AuditLogger::class)->record('user_location_attached', $user, ['location_id' => $location->getKey()]);
            }
            $user->unsetRelation('locations');
        });
    }

    public function detach(User $user, Location $location): bool
    {
        abort_unless($this->canManage($user, $location), 403);

        return DB::transaction(function () use ($user, $location): bool {
            $detached = $user->locations()->detach($location->getKey()) > 0;
            if ($detached) {
                app(AuditLogger::class)->record('user_location_detached', $user, ['location_id' => $location->getKey()]);
            }
            $user->unsetRelation('locations');

            return $detached;
        });
    }
}
