<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Lets only requests authenticated with a user-created API key through, as the MCP endpoint is meant for those */
class RequireApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $key = EnforceApiKeyScopes::keyOf($request);
        if ($key === null) {
            return response()->json(['message' => 'An API key is required'], 403);
        }
        if ($key->expires_at !== null && $key->expires_at->isPast()) {
            return response()->json(['message' => 'This API key has expired'], 401);
        }

        return $next($request);
    }
}
