<?php

namespace App\Modules\AccessManagement\Http\Middleware;

use App\Modules\AccessManagement\Services\LocationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResetLocationPermissionContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(LocationContext::class);
        $context->clear();
        try {
            return $next($request);
        } finally {
            $context->clear();
            auth('web')->user()?->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
