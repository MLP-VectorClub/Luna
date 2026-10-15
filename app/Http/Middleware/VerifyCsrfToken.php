<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * Indicates whether the XSRF-TOKEN cookie should be set on the response.
     *
     * @var bool
     */
    protected $addHttpCookie = true;

    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        // Scanners post to whatever they find, the answer is a refusal either way
        'honeypot/*',
    ];

    /** A guest whose read started no session (see StartSession) has nothing to take a token from, and a token cookie would be one nothing stands behind */
    public function handle($request, \Closure $next)
    {
        if ($request->attributes->get('sessionless')) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
