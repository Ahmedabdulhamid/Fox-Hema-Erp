<?php

namespace App\Modules\AccessManagement\Http\Middleware;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SetLocationPermissionContext
{
    public function __construct(private readonly LocationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User) {
            $this->context->initializeFor($user);
            if ($request->hasSession() && config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))
                    ->where('id', $request->session()->getId())
                    ->whereNull('login_at')
                    ->update(['login_at' => $user->last_login_at ?? now()]);
            }
        }

        return $next($request);
    }
}
