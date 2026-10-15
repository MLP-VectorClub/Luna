<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as Middleware;

/**
 * Laravel's StartSession, except for the guests of the API who have nothing to keep: a read (GET, HEAD, OPTIONS) from the front end that comes without a
 * session cookie, a remember me cookie or any of the few things that need a session starts none. Every such request used to make the database
 * keep a row (the front end fires a dozen at a time on a first visit, search engines and monitors never send a cookie at all), which came to thousands
 * of rows a day that nothing ever used again.
 *
 * The session of a visitor starts where it is needed: `GET /sanctum/csrf-cookie`, which the front end calls first, and every request that writes.
 * It is bound in place of Laravel's own (AppServiceProvider), so the pipeline of Sanctum's stateful requests uses it.
 */
class StartSession extends Middleware
{
    /** Paths of requests that are reads and still need their session: the CSRF handshake, the traps (they flag it), the OAuth redirects and the test sign in */
    private const NEEDS_SESSION = ['sanctum/csrf-cookie', 'honeypot/*', 'users/oauth/*', 'test/*', 'internal/session-status'];

    public function handle($request, Closure $next)
    {
        if ($this->isGuestRead($request)) {
            // Marks the request for what comes after (VerifyCsrfToken has no session to take a token from)
            $request->attributes->set('sessionless', true);

            return $next($request);
        }

        return parent::handle($request, $next);
    }

    private function isGuestRead(Request $request): bool
    {
        // Only inside Sanctum's pipeline (the web group has middleware that needs a session store, and its few routes can keep one)
        if (!$request->attributes->get('sanctum') || !$request->isMethodSafe() || $request->is(...self::NEEDS_SESSION)) {
            return false;
        }
        if (is_string($request->cookies->get(config('session.cookie'))) && $request->cookies->get(config('session.cookie')) !== '') {
            return false;
        }
        // A remember me cookie signs the user in again, which needs the session to put the login into
        foreach ($request->cookies->keys() as $name) {
            if (str_starts_with((string) $name, 'remember_')) {
                return false;
            }
        }

        return true;
    }
}
