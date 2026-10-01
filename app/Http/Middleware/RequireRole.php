<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Usage: `role:staff`. Must run after `auth:sanctum`; answers 401 for guests and 403 when the role is not sufficient
 */
class RequireRole
{
    public function handle(Request $request, Closure $next, string $role)
    {
        $user = $request->user();
        if ($user === null) {
            throw new AuthenticationException();
        }

        if (!perm(Role::from($role), $user->role)) {
            throw new HttpException(403, 'You do not have permission to do this');
        }

        return $next($request);
    }
}
