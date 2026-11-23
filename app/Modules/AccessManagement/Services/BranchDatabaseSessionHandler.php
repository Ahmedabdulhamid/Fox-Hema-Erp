<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\User;
use Illuminate\Session\DatabaseSessionHandler;

class BranchDatabaseSessionHandler extends DatabaseSessionHandler
{
    /** @return array<string, mixed> */
    protected function getDefaultPayload(mixed $data): array
    {
        $payload = parent::getDefaultPayload($data);
        $payload['location_id'] = null;
        $user = $this->container?->make('auth')->guard('web')->user();
        $locationId = $this->container?->make('session.store')->get(LocationContext::SESSION_KEY);
        if ($user instanceof User && RequestAuthorizationFacts::actorIsActive($user)
            && (is_int($locationId) || (is_string($locationId) && ctype_digit($locationId)))
            && (int) $locationId > 0 && RequestAuthorizationFacts::locationIsActive((int) $locationId)
            && ($user->isGlobalSuperAdmin() || RequestAuthorizationFacts::actorHasLocation($user, (int) $locationId))) {
            $payload['location_id'] = (int) $locationId;
        }

        return $payload;
    }
}
