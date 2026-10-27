<?php

namespace App\Http\Middleware;

use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BeginAuthorizationRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        RequestAuthorizationFacts::begin($request);
        try {
            return $next($request);
        } finally {
            RequestAuthorizationFacts::end($request);
        }
    }
}
