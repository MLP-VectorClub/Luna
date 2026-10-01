<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the Sanctum user (bearer token or stateful cookie) when one is present, without rejecting guests.
 * Public endpoints use it so they can expose per-visitor data like `canEdit`.
 */
class OptionalAuth
{
    public function handle(Request $request, Closure $next)
    {
        Auth::shouldUse('sanctum');
        $request->setUserResolver(fn() => Auth::guard('sanctum')->user());

        return $next($request);
    }
}
