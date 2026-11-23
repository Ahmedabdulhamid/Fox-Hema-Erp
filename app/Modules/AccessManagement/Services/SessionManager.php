<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\DatabaseSession;
use App\Modules\AccessManagement\Models\User;
use Illuminate\Support\Facades\Gate;

class SessionManager
{
    public function revoke(string $sessionId, ?User $target, bool $allowCurrent = false): bool
    {
        if (! $allowCurrent && request()->hasSession() && hash_equals(request()->session()->getId(), $sessionId)) {
            return false;
        }

        $query = DatabaseSession::query()->whereKey($sessionId);
        if (auth()->check()) {
            $session = (clone $query)->first();
            if ($session === null) {
                return false;
            }
            Gate::authorize('delete', $session);
            $query->visibleTo(auth()->user());
        }
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
        $query = DatabaseSession::query()->where('user_id', $target->getKey());
        $actor = auth()->user();
        if ($actor !== null && ! $actor->is($target) && ! $actor->isGlobalSuperAdmin()) {
            if ($target->is_active || ! $actor->can('update', $target)) {
                Gate::authorize('revokeSessions', $target);
            }
            $query->inActorLocation($actor);
        }
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
