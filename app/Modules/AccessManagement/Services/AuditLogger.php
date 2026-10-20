<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\AuthEvent;
use App\Modules\AccessManagement\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    private const SENSITIVE_KEYS = ['password', 'password_confirmation', 'token', 'remember_token', 'cookie', 'session_cookie'];

    public function record(string $event, ?User $target = null, array $metadata = [], ?User $actor = null, ?Request $request = null): AuthEvent
    {
        $request ??= request();
        $actor ??= auth()->user();

        return AuthEvent::query()->create([
            'actor_user_id' => $actor?->getKey(),
            'target_user_id' => $target?->getKey(),
            'event' => $event,
            'location_id' => getPermissionsTeamId(),
            'session_id' => $request->hasSession() ? $request->session()->getId() : null,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'metadata' => $this->sanitize($metadata),
            'occurred_at' => now(),
        ]);
    }

    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }

        return $data;
    }
}
