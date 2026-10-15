<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A session that touched a honeypot (`HoneypotController`) is refused for good. The flag lives in the session row of the database, nothing the
 * browser holds can undo it: the front end's server asks `GET /internal/session-status` with the visitor's session cookie before it serves a page
 */
class RejectFlaggedSession
{
    public const SESSION_KEY = 'blocked_at';

    public static function flag(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, now()->toIso8601String());
    }

    public static function refusal(): Response
    {
        return response('Forbidden', 403);
    }

    public function handle(Request $request, Closure $next)
    {
        // Token requests have no session (and sessions that never reached a honeypot have no flag)
        if ($request->hasSession() && $request->session()->has(self::SESSION_KEY)) {
            return self::refusal();
        }

        return $next($request);
    }
}
