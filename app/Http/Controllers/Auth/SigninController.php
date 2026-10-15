<?php

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\SocialAuthRequest;
use App\Models\User;
use App\Utils\AccountHelper;
use App\Utils\DeviantArtTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use OpenApi\Annotations as OA;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SigninController extends Controller
{
    /**
     * @OA\Schema(
     *   schema="SigninRequest",
     *   type="object",
     *   required={
     *     "email",
     *     "password"
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="email",
     *     type="string"
     *   ),
     *   @OA\Property(
     *     property="password",
     *     type="string"
     *   ),
     *   @OA\Property(
     *     property="remember",
     *     type="boolean",
     *     description="When using session-based auth set to true for persistent cookies, omit or use false for session cookies"
     *   )
     * )
     * @OA\Post(
     *   path="/users/signin",
     *   description="Used for obtaining an API access token",
     *   tags={"authentication"},
     *   security={},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(ref="#/components/schemas/SigninRequest")
     *   ),
     *   @OA\Response(
     *     response="200",
     *     description="Authentication successful",
     *     @OA\JsonContent(
     *       additionalProperties=false,
     *       @OA\Property(
     *         property="token",
     *         type="string"
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response="204",
     *     description="Session-based authentication successful (authentication via cookies, no token is sent)"
     *   ),
     *   @OA\Response(
     *     response="202",
     *     description="The password is right but the account has two-factor authentication: nobody is signed in yet, answer the challenge at `POST /users/signin/two-factor`",
     *     @OA\JsonContent(ref="#/components/schemas/TwoFactorChallengeResponse")
     *   ),
     *   @OA\Response(
     *     response="403",
     *     description="Could not sign in, check the error message for details",
     *     @OA\Schema(ref="#/components/schemas/ErrorResponse")
     *   ),
     *   @OA\Response(
     *     response="401",
     *     description="Invalid credentials",
     *   )
     * )
     *
     * @param  Request  $request
     * @return JsonResponse|Response
     * @throws ValidationException
     */
    public function viaPassword(Request $request)
    {
        $is_sanctum = $request->attributes->get('sanctum') === true;
        if ($is_sanctum && $request->user() !== null) {
            return response()->noContent();
        }

        $data = Validator::make($request->only(['email', 'password', 'remember']), [
            'email' => 'required|string',
            'password' => 'required|string',
            'remember' => 'sometimes|required|boolean',
        ])->validate();

        $user = User::whereEmail($data['email'])->first();

        $password = empty($user) ? '' : $user->password;

        if ($password === null) {
            return response()->json(['message' => trans('errors.auth.no_password_set')], 403);
        }

        if (!Hash::check($data['password'], $password)) {
            abort(401);
        }

        if ($blocked = self::deviantArtBlock($user)) {
            return $blocked;
        }

        // The password alone is not enough for an account with an authenticator app: nothing is signed in yet, the client gets a challenge to answer
        if ($user->hasTwoFactor()) {
            return TwoFactorSigninController::challengeResponse($user, $data['remember'] ?? false);
        }

        return AccountHelper::authResponse($request, $user, $data['remember'] ?? false);
    }

    /**
     * Members signed in with DeviantArt first, so their DeviantArt tokens have to keep working: the response that stops the sign-in when they do not
     * (every way of signing in as the member, except DeviantArt itself, asks)
     */
    public static function deviantArtBlock(User $user): ?JsonResponse
    {
        $da_user = DeviantArtTokens::enabled() ? $user->daUser : null;
        if ($da_user === null) {
            return null;
        }

        $status = DeviantArtTokens::check($da_user);
        if ($status === DeviantArtTokens::REVOKED) {
            return response()->json(['message' => trans('errors.auth.deviantart_required'), 'deviantArtRequired' => true], 403);
        }
        if ($status === DeviantArtTokens::UNAVAILABLE) {
            return response()->json(['message' => trans('errors.auth.deviantart_unavailable')], 503);
        }

        return null;
    }

    /**
     * @OA\Get(
     *   path="/users/oauth/signin/{provider}",
     *   description="Redirect to the specified OAuth provider's authorization endpoint",
     *   tags={"authentication"},
     *   security={},
     *   @OA\Parameter(
     *     in="path",
     *     name="provider",
     *     required=true,
     *     @OA\Schema(ref="#/components/schemas/SocialProvider"),
     *     description="The name of the provider to log in with"
     *   ),
     *   @OA\Response(
     *     response="302",
     *     description="Redirect to the authorization endpoint",
     *     @OA\Header(
     *       header="Location",
     *       @OA\Schema(ref="#/components/schemas/LocationHeader")
     *     )
     *   ),
     *   @OA\Response(
     *     response="400",
     *     description="Unsupported provider",
     *     @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse")
     *   )
     * )
     *
     * @param  SocialAuthRequest  $request
     * @return RedirectResponse
     */
    public function socialiteRedirect(SocialAuthRequest $request)
    {
        return AccountHelper::socialRedirect($request);
    }

    /**
     * @OA\Schema(
     *   schema="OauthCode",
     *   type="object",
     *   required={
     *     "code",
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="code",
     *     type="string",
     *     description="The authorization code received from the provider"
     *   )
     * )
     * @OA\Post(
     *   path="/users/oauth/signin/{provider}",
     *   description="Process an OAuth authorization response. A DeviantArt user without an account gets one (the only way to sign up), named after their DeviantArt name",
     *   tags={"authentication"},
     *   security={},
     *   @OA\Parameter(
     *     in="path",
     *     name="provider",
     *     required=true,
     *     @OA\Schema(ref="#/components/schemas/SocialProvider"),
     *     description="The name of the provider the response is coming from"
     *   ),
     *   @OA\RequestBody(
     *       required=true,
     *       @OA\JsonContent(ref="#/components/schemas/OauthCode")
     *   ),
     *   @OA\Response(
     *     response="200",
     *     description="Authentication successful",
     *     @OA\JsonContent(
     *       additionalProperties=false,
     *       @OA\Property(
     *         property="token",
     *         type="string"
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response="204",
     *     description="Authentication successful (authentication via cookies, no token is sent)"
     *   ),
     *   @OA\Response(
     *     response="400",
     *     description="Unsupported provider",
     *     @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse")
     *   ),
     *   @OA\Response(
     *     response="403",
     *     description="Already logged in via session-based authentication",
     *   ),
     *   @OA\Response(
     *     response="404",
     *     description="No account is linked to the provider's user (Discord signs in to existing accounts only)",
     *     @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *   ),
     *   @OA\Response(
     *     response="409",
     *     description="A DeviantArt user signing in for the first time gets an account, and its name is used by another account",
     *     @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *   )
     * )
     *
     * @param  SocialAuthRequest  $request
     * @return JsonResponse|Response
     */
    public function viaSocialite(SocialAuthRequest $request)
    {
        return AccountHelper::socialAuth($request);
    }
}
