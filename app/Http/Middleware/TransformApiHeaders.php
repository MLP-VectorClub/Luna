<?php

namespace App\Http\Middleware;

use Closure;

class TransformApiHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');
        $referer = $request->headers->get('referer');
        if ($referer === null) {
            $origin = $request->headers->get('x-app-origin');
            if ($origin) {
                $request->headers->set('referer', $origin);
            }
        }
        return $next($request);
    }
}
