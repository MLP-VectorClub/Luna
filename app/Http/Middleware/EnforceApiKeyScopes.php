<?php

namespace App\Http\Middleware;

use App\Enums\ApiKeyScope;
use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Narrows what a user-created API key may do on top of what its owner's role allows. Requests that carry anything else (cookies, sign-in
 * tokens, no credentials) pass through untouched
 */
class EnforceApiKeyScopes
{
    /** Routes about how the account signs in or is reached, which stay with a browser session */
    private const SESSION_ONLY = [
        'users/me', 'users/me/*', 'users/tokens', 'users/tokens/*', 'users/sessions', 'users/sessions/*', 'users/signout', 'users/email/*',
        'users/*/sessions', 'users/*/sessions/*', 'users/*/discord', 'users/*/discord/*', 'users/*/email-changes',
    ];

    /** First path segment of a write → scope that allows it */
    private const WRITE_SCOPES = [
        'appearances' => ApiKeyScope::AppearancesWrite,
        'color-groups' => ApiKeyScope::AppearancesWrite,
        'posts' => ApiKeyScope::PostsWrite,
    ];

    public function handle(Request $request, Closure $next)
    {
        $token = self::keyOf($request);
        if ($token === null) {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        if ($request->is(...self::SESSION_ONLY)) {
            return $this->deny('This endpoint is not available to API keys');
        }

        if ($request->isMethodSafe()) {
            $needsAuth = in_array('auth:sanctum', $request->route()?->gatherMiddleware() ?? [], true);
            $scope = $needsAuth ? ApiKeyScope::ReadOwn : ApiKeyScope::Read;
            // An owner-level read also covers public data
            if ($token->can($scope->value) || ($scope === ApiKeyScope::Read && $token->can(ApiKeyScope::ReadOwn->value))) {
                return $next($request);
            }

            return $this->deny("This API key lacks the {$scope->value} scope");
        }

        $segments = explode('/', $path);
        $scope = $request->is('users/*/preferences/*') ? ApiKeyScope::UserWrite : (self::WRITE_SCOPES[$segments[0]] ?? null);
        if ($scope !== null && $token->can($scope->value)) {
            return $next($request);
        }

        return $this->deny($scope === null ? 'This endpoint is not available to API keys' : "This API key lacks the {$scope->value} scope");
    }

    /** The API key the request is authenticated with, if any */
    public static function keyOf(Request $request): ?PersonalAccessToken
    {
        if ($request->bearerToken() === null) {
            return null;
        }

        $token = Auth::guard('sanctum')->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken && $token->is_api_key ? $token : null;
    }

    private function deny(string $message)
    {
        return response()->json(['message' => $message], 403);
    }
}
