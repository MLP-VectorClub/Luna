<?php

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\SocialAuthRequest;
use App\Models\User;
use App\Utils\AccountHelper;
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
     *     schema="SigninRequest",
     *     type="object",
     *     required={
     *         "email",
     *         "password"
     *     },
     *     additionalProperties=false,
     *     @OA\Property(
     *         property="email",
     *         type="string"
     *     ),
     *     @OA\Property(
     *         property="password",
     *         type="string"
     *     ),
     *     @OA\Property(
     *         property="remember",
     *         type="boolean",
     *         description="When using session-based auth set to true for persistent cookies, omit or use false for session cookies"
     *     )
     * )
     * @OA\Post(
     *     path="/users/signin",
     *     description="Used for obtaining an API access token",
     *     tags={"authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/SigninRequest")
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="Authentication successful",
     *         @OA\JsonContent(
     *             additionalProperties=false,
     *             @OA\Property(
     *                 property="token",
     *                 type="string"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response="204",
     *         description="Session-based authentication successful (authentication via cookies, no token is sent)"
     *     ),
     *     @OA\Response(
     *         response="403",
     *         description="Could not sign in, check the error message for details",
     *         @OA\Schema(ref="#/components/schemas/ErrorResponse")
     *     ),
     *     @OA\Response(
     *         response="401",
     *         description="Invalid credentials",
     *     )
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

        return AccountHelper::authResponse($request, $user, false, $data['remember'] ?? false);
    }

    /**
     * @OA\Get(
     *   path="/users/oauth/signin/{provider}",
     *   description="Redirect to the specified OAuth provider's authorization endpoint",
     *   tags={"authentication"},
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
     *     @OA\Header(header="Location", ref="#/components/schemas/LocationHeader")
     *   ),
     *   @OA\Response(
     *     response="400",
     *     description="Unsupported provider",
     *     @OA\JsonContent(
     *       ref="#/components/schemas/ValidationErrorResponse"
     *     )
     *   )
     * )
     *
     * @param  SocialAuthRequest  $request
     * @return RedirectResponse
     */
    public function socialiteRedirect(SocialAuthRequest $request)
    {
        return AccountHelper::socialRedirect($request, false);
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
     *   description="Process an OAuth authorization response",
     *   tags={"authentication"},
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
     *   @OA\Parameter(
     *     in="query",
     *     name="code",
     *     required=true,
     *     schema={
     *       "type": "string"
     *     },
     *     description="The authorization code from the provider"
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
     *     response="503",
     *     description="Registrations are not possible at the moment",
     *     @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *   )
     * )
     *
     * @param  SocialAuthRequest  $request
     * @return JsonResponse|Response
     */
    public function viaSocialite(SocialAuthRequest $request)
    {
        return AccountHelper::socialAuth($request, false);
    }
}
