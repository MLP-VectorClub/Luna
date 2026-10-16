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
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SigninController extends Controller
{
    /**
     * @param  Request  $request
     * @return JsonResponse|Response
     * @throws ValidationException
     */
    #[OA\Schema(
        schema: 'SigninRequest',
        type: 'object',
        required: ['email', 'password'],
        additionalProperties: false,
        properties: [
            new OA\Property(property: 'email', type: 'string'),
            new OA\Property(property: 'password', type: 'string'),
            new OA\Property(
                property: 'remember',
                type: 'boolean',
                description: 'When using session-based auth set to true for persistent cookies, omit or use false for session cookies',
            ),
        ],
    )]
    #[OA\Post(
        path: '/users/signin',
        description: 'Used for obtaining an API access token',
        tags: ['authentication'],
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/SigninRequest'),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Authentication successful',
                content: new OA\JsonContent(
                    additionalProperties: false,
                    properties: [new OA\Property(property: 'token', type: 'string')],
                ),
            ),
            new OA\Response(
                response: '204',
                description: 'Session-based authentication successful (authentication via cookies, no token is sent)',
            ),
            new OA\Response(
                response: '202',
                description: 'The password is right but the account has two-factor authentication: nobody is signed in yet, answer the challenge at `POST /users/signin/two-factor`',
                content: new OA\JsonContent(ref: '#/components/schemas/TwoFactorChallengeResponse'),
            ),
            new OA\Response(response: '403', description: 'Could not sign in, check the error message for details'),
            new OA\Response(response: '401', description: 'Invalid credentials'),
        ],
    )]
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
     * @param  SocialAuthRequest  $request
     * @return RedirectResponse
     */
    #[OA\Get(
        path: '/users/oauth/signin/{provider}',
        description: 'Redirect to the specified OAuth provider\'s authorization endpoint',
        tags: ['authentication'],
        security: [],
        parameters: [
            new OA\Parameter(
                in: 'path',
                name: 'provider',
                required: true,
                description: 'The name of the provider to log in with',
                schema: new OA\Schema(ref: '#/components/schemas/SocialProvider'),
            ),
        ],
        responses: [
            new OA\Response(
                response: '302',
                description: 'Redirect to the authorization endpoint',
                headers: [
                    new OA\Header(
                        header: 'Location',
                        schema: new OA\Schema(ref: '#/components/schemas/LocationHeader'),
                    ),
                ],
            ),
            new OA\Response(
                response: '400',
                description: 'Unsupported provider',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
        ],
    )]
    public function socialiteRedirect(SocialAuthRequest $request)
    {
        return AccountHelper::socialRedirect($request);
    }

    /**
     * @param  SocialAuthRequest  $request
     * @return JsonResponse|Response
     */
    #[OA\Schema(
        schema: 'OauthCode',
        type: 'object',
        required: ['code'],
        additionalProperties: false,
        properties: [
            new OA\Property(
                property: 'code',
                type: 'string',
                description: 'The authorization code received from the provider',
            ),
        ],
    )]
    #[OA\Post(
        path: '/users/oauth/signin/{provider}',
        description: 'Process an OAuth authorization response. A DeviantArt user without an account gets one (the only way to sign up), named after their DeviantArt name',
        tags: ['authentication'],
        security: [],
        parameters: [
            new OA\Parameter(
                in: 'path',
                name: 'provider',
                required: true,
                description: 'The name of the provider the response is coming from',
                schema: new OA\Schema(ref: '#/components/schemas/SocialProvider'),
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/OauthCode'),
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: 'Authentication successful',
                content: new OA\JsonContent(
                    additionalProperties: false,
                    properties: [new OA\Property(property: 'token', type: 'string')],
                ),
            ),
            new OA\Response(
                response: '204',
                description: 'Authentication successful (authentication via cookies, no token is sent)',
            ),
            new OA\Response(
                response: '400',
                description: 'Unsupported provider',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
            ),
            new OA\Response(response: '403', description: 'Already logged in via session-based authentication'),
            new OA\Response(
                response: '404',
                description: 'No account is linked to the provider\'s user (Discord signs in to existing accounts only)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
            new OA\Response(
                response: '409',
                description: 'A DeviantArt user signing in for the first time gets an account, and its name is used by another account',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            ),
        ],
    )]
    public function viaSocialite(SocialAuthRequest $request)
    {
        return AccountHelper::socialAuth($request);
    }
}
