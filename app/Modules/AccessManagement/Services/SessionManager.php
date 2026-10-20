<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\User;
use Illuminate\Support\Facades\DB;

class SessionManager
{
    public function revoke(string $sessionId, ?User $target, bool $allowCurrent = false): bool
    {
        if (! $allowCurrent && request()->hasSession() && hash_equals(request()->session()->getId(), $sessionId)) {
            return false;
        }

        $query = DB::table(config('session.table', 'sessions'))->where('id', $sessionId);
        $target === null
            ? $query->whereNull('user_id')
            : $query->where('user_id', $target->getKey());

        $deleted = $query->delete() > 0;
        if ($deleted) {
            app(AuditLogger::class)->record('session_revoked', $target, ['revoked_session_id_hash' => hash('sha256', $sessionId)]);
        }

        return $deleted;
    }

    public function revokeAll(User $target, ?string $except = null): int
    {
        $query = DB::table(config('session.table', 'sessions'))->where('user_id', $target->getKey());
        if ($except !== null) {
            $query->where('id', '!=', $except);
        }
        $count = $query->delete();
        if ($count > 0) {
            app(AuditLogger::class)->record('sessions_revoked', $target, ['count' => $count]);
        }

        return $count;
    }
}
