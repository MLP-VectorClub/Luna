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
        $app_referer = $request->headers->get('x-referer');
        if ($app_referer) {
            $request->headers->set('referer', $app_referer);
        }
        return $next($request);
    }
}
