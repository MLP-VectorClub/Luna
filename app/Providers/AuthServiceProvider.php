<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Laravel\Passport\Passport;
use OpenApi\Annotations as OA;

/**
 * @OA\SecurityScheme(
 *   securityScheme="DefaultSecurityScheme",
 *   name="bearerAuth",
 *   type="http",
 *   scheme="bearer"
 * )
 * @OA\Post(
 *   path="/oauth/token",
 *   description="Used for obtaining an API access token",
 *   tags={"authentication"},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\MediaType(
 *       mediaType="application/json",
 *       @OA\Schema(
 *         schema="AuthTokenRequest",
 *         type="object",
 *         required={
 *           "grant_type",
 *           "client_id",
 *           "username",
 *           "password",
 *           "scope"
 *         },
 *         additionalProperties=false,
 *         @OA\Property(
 *           property="grant_type",
 *           type="string",
 *           enum={"password"}
 *         ),
 *         @OA\Property(
 *           property="client_id",
 *           type="number"
 *         ),
 *         @OA\Property(
 *           property="username",
 *           type="string"
 *         ),
 *         @OA\Property(
 *           property="password",
 *           type="string"
 *         ),
 *         @OA\Property(
 *           property="scope",
 *           type="string"
 *         )
 *       )
 *     )
 *   ),
 *   @OA\Response(
 *     response="200",
 *     description="Authentication successful",
 *     @OA\JsonContent(
 *       @OA\Schema(
 *         schema="AuthTokenResponse",
 *         type="object",
 *         required={
 *           "access_token",
 *           "refresh_token",
 *           "expires_in"
 *         },
 *         additionalProperties=false,
 *         @OA\Property(
 *           property="access_token",
 *           type="string"
 *         ),
 *         @OA\Property(
 *           property="refresh_token",
 *           type="string"
 *         ),
 *         @OA\Property(
 *           property="expires_in",
 *           type="number",
 *           description="The number of seconds until the access token expires"
 *         )
 *       )
 *     )
 *   ),
 *   @OA\Response(
 *     response="401",
 *     description="Invalid credentials",
 *   )
 * )
 * @OA\Response(
 *   response="UnauthorizedError",
 *   description="Access token is missing or invalid"
 * )
 */
class AuthServiceProvider extends ServiceProvider {
  /**
   * The policy mappings for the application.
   *
   * @var array
   */
  protected $policies = [
    // 'App\Model' => 'App\Policies\ModelPolicy',
  ];

  /**
   * Register any authentication / authorization services.
   *
   * @return void
   */
  public function boot() {
    $this->registerPolicies();

    Passport::routes();
  }
}
