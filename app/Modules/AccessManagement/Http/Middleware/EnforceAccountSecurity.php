<?php

namespace App\Modules\AccessManagement\Http\Middleware;

use App\Modules\AccessManagement\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceAccountSecurity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }
        if (! $user->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->to('/admin/login')->withErrors(['email' => __('This account is disabled.')]);
        }
        if ($user->must_change_password && ! $request->routeIs('access-management.password.*') && ! $request->routeIs('filament.admin.auth.logout')) {
            return redirect()->route('access-management.password.edit');
        }

        return $next($request);
    }
}
