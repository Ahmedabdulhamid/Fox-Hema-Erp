<?php

namespace App\Modules\AccessManagement\Http\Middleware;

use App\Modules\AccessManagement\Services\ModuleAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleActive
{
    public function handle(Request $request, Closure $next, string $code): Response
    {
        abort_unless(app(ModuleAccess::class)->isActive($code), 403);

        return $next($request);
    }
}
