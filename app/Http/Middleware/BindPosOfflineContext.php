<?php

namespace App\Http\Middleware;

use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Services\PosAccess;
use App\Modules\POS\Services\PosPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BindPosOfflineContext
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $identity = $this->identity($request);
        $response->headers->setCookie(cookie('fox_pos_context', $identity ?? '',
            $identity === null ? -1 : (int) config('session.lifetime'), '/', null,
            $request->isSecure(), false, false, 'strict'));

        return $response;
    }

    /** @param array{branch_id: int, device_id: int, session_id: int, cashier_id: int}|null $scope */
    public function bind(Request $request, ?array $scope): ?string
    {
        if ($scope === null) {
            $request->session()->forget('pos.offline_scope');

            return null;
        }
        $actor = $request->user();
        abort_unless($actor !== null && (int) $actor->id === $scope['cashier_id'], 403);
        app(PosAccess::class)->authorize($actor, PosPermissions::CHECKOUT, $scope['branch_id']);
        abort_unless(PosSession::query()->whereKey($scope['session_id'])->where('opened_by', $actor->id)
            ->where('location_id', $scope['branch_id'])->where('pos_device_id', $scope['device_id'])
            ->where('status', 'open')->whereNull('closing_started_at')->whereNull('closed_at')
            ->whereHas('posDevice', fn ($query) => $query->where('is_active', true)->whereNull('revoked_at')
                ->where('location_id', $scope['branch_id']))->exists(), 403);
        $request->session()->put('pos.offline_scope', $scope);

        return $this->identity($request);
    }

    public function identity(Request $request): ?string
    {
        $scope = $request->session()->get('pos.offline_scope');
        $actor = $request->user();
        if (! is_array($scope) || $actor === null || ! $actor->is_active || $actor->must_change_password
            || (int) $actor->id !== ($scope['cashier_id'] ?? null)
            || (int) $request->session()->get(LocationContext::SESSION_KEY) !== ($scope['branch_id'] ?? null)) {
            return null;
        }

        $session = PosSession::query()->with('posDevice')->whereKey($scope['session_id'] ?? 0)
            ->where('opened_by', $actor->id)->where('location_id', $scope['branch_id'])
            ->where('pos_device_id', $scope['device_id'])->where('status', 'open')
            ->whereNull('closing_started_at')->whereNull('closed_at')->first();
        if ($session === null || ! $session->posDevice?->is_active || $session->posDevice->revoked_at !== null
            || (int) $session->posDevice->location_id !== $scope['branch_id']) {
            return null;
        }
        $connection = $session->getConnection();
        $serverIdentity = [$connection->getConfig('host'), $connection->getConfig('port'), $connection->getDatabaseName(),
            $actor->uuid, $session->uuid, $session->posDevice->uuid];

        return hash_hmac('sha256', json_encode([$request->session()->getId(), $scope, $serverIdentity], JSON_THROW_ON_ERROR), config('app.key'));
    }
}
