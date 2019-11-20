<?php

namespace App\Http\Middleware;

use Closure;
use Laravel\Passport\Passport;

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
        $cookie_name = Passport::cookie();
        $token_cookie = $request->cookie($cookie_name);
        $request->cookies->remove($cookie_name);
        $headers = ['Accept' => 'application/json'];
        if ($token_cookie !== null) {
            $headers['Authorization'] = "Bearer $token_cookie";
        }
        $request->headers->add($headers);
        return $next($request);
    }
}
