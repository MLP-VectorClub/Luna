<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use OpenApi\Annotations as OA;

/**
 * @OA\SecurityScheme(
 *     securityScheme="BearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     description="Can be used to authenticate using a token sent via HTTP headers"
 * )
 * @OA\SecurityScheme(
 *     securityScheme="CookieAuth",
 *     type="apiKey",
 *     in="cookie",
 *     name="mlp_vector_club_session",
 *     description="Used for session-based authentication, the cookie is set by the backend on qualifying requests (i.e. browser requests originating from our domain)"
 * )
 */
class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();
    }
}
