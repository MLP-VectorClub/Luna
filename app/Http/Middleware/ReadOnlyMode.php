<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * While the site is read-only (`READ_ONLY=true`) it only shows what the database holds: everything that is not a plain read, signing in
 * included, answers 503
 */
class ReadOnlyMode
{
    /** Only checks a token against the database, the websocket server needs it to keep working */
    private const ALLOWED = ['api/internal/websocket/validate', 'internal/websocket/validate'];

    public function handle(Request $request, Closure $next)
    {
        if (config('app.read_only') && !$request->isMethodSafe() && !in_array($request->path(), self::ALLOWED, true)) {
            return response()->json(['message' => 'The site is read-only right now, nothing can be changed'], 503);
        }

        return $next($request);
    }
}
