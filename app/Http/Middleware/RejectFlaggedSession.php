<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;

/**
 * A session that touched a honeypot (`HoneypotController`) is refused for good. The flag lives in the session; because the front end cannot read
 * the session, a plain `blocked` cookie (not encrypted, see `EncryptCookies`) tells it the same thing so it can refuse its own pages too
 */
class RejectFlaggedSession
{
    public const SESSION_KEY = 'blocked_at';

    public const COOKIE = 'blocked';

    public static function flag(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, now()->toIso8601String());
    }

    public static function refusal(): Response
    {
        return response('Forbidden', 403)->withCookie(Cookie::make(self::COOKIE, '1', 60 * 24 * 365, '/', null, true, true, false, 'lax'));
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
