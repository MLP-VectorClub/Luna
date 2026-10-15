<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TwoFactor;
use App\Models\User;
use App\Utils\AccountHelper;
use App\Utils\Passkeys;
use App\Utils\SignedChallenge;
use App\Utils\TwoFactorAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;

/**
 * The second step of signing in to an account with two-factor authentication. The first step (`POST /users/signin`) checked the password and handed out a
 * challenge, an encrypted token that holds the account and expires in five minutes, which this step has to present with a code, a recovery code or a passkey
 *
 * @OA\Schema(
 *   schema="TwoFactorChallengeResponse",
 *   type="object",
 *   required={"twoFactor"},
 *   additionalProperties=false,
 *   @OA\Property(
 *     property="twoFactor",
 *     type="object",
 *     required={"challenge", "methods"},
 *     additionalProperties=false,
 *     @OA\Property(property="challenge", type="string", description="Send this back with the answer"),
 *     @OA\Property(property="methods", type="array", description="What the account can answer with", @OA\Items(type="string", enum={"totp", "recovery", "passkey"}))
 *   )
 * )
 */
class TwoFactorSigninController extends Controller
{
    private const PURPOSE = 'two-factor';
    private const PASSKEY_PURPOSE = 'two-factor-passkey';
    private const TTL = 300;
    private const MAX_TRIES = 5;
    private const MAX_FAILURES_PER_USER = 10;
    private const DECAY_SECONDS = 900;

    /** The 202 that `POST /users/signin` answers with */
    public static function challengeResponse(User $user, bool $remember): JsonResponse
    {
        $methods = ['totp', 'recovery'];
        if ($user->passkeys()->exists()) {
            $methods[] = 'passkey';
        }

        return response()->json(['twoFactor' => [
            'challenge' => SignedChallenge::issue(self::PURPOSE, ['user' => $user->id, 'remember' => $remember], self::TTL),
            'methods' => $methods,
        ]], 202);
    }

    private static function expired(): JsonResponse
    {
        return response()->json(['message' => 'This sign-in has expired, please start over', 'expired' => true], 401);
    }

    /**
     * @OA\Post(
     *   path="/users/signin/two-factor",
     *   description="Answers the challenge of `POST /users/signin` with a code of the authenticator app or a recovery code, and signs in",
     *   tags={"authentication"},
     *   security={},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       type="object",
     *       required={"challenge"},
     *       additionalProperties=false,
     *       @OA\Property(property="challenge", type="string"),
     *       @OA\Property(property="code", type="string", description="Six digits of the authenticator app"),
     *       @OA\Property(property="recoveryCode", type="string", description="One of the recovery codes, each works once")
     *     )
     *   ),
     *   @OA\Response(response="200", description="Authentication successful", @OA\JsonContent(type="object", required={"token"}, additionalProperties=false, @OA\Property(property="token", type="string"))),
     *   @OA\Response(response="204", description="Session-based authentication successful (cookies, no token is sent)"),
     *   @OA\Response(response="401", description="The code is wrong (more tries left), or the challenge has expired or was used up (`expired: true`)"),
     *   @OA\Response(response="422", description="Neither `code` nor `recoveryCode` was sent", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse")),
     *   @OA\Response(response="429", description="Too many wrong codes for this account")
     * )
     */
    public function verify(Request $request)
    {
        $input = $request->validate([
            'challenge' => 'required|string',
            'code' => 'sometimes|string|max:64',
            'recoveryCode' => 'sometimes|string|max:64',
        ]);
        if (!isset($input['code']) && !isset($input['recoveryCode'])) {
            throw ValidationException::withMessages(['code' => 'A code is required']);
        }

        $challenge = SignedChallenge::read(self::PURPOSE, $input['challenge']);
        $user = $challenge === null ? null : User::find($challenge['data']['user'] ?? 0);
        $factor = $user === null ? null : TwoFactor::where('user_id', $user->id)->whereNotNull('confirmed_at')->first();
        if ($factor === null) {
            return self::expired();
        }

        $limiter = "two-factor:{$user->id}";
        if (RateLimiter::tooManyAttempts($limiter, self::MAX_FAILURES_PER_USER)) {
            abort(429, 'Too many wrong codes, try again later', ['Retry-After' => RateLimiter::availableIn($limiter)]);
        }

        $good = isset($input['code'])
            ? TwoFactorAuth::verifyCode($factor, $input['code'])
            : TwoFactorAuth::useRecoveryCode($user, $input['recoveryCode']);
        if (!$good) {
            RateLimiter::hit($limiter, self::DECAY_SECONDS);
            if (!SignedChallenge::failed($challenge, self::MAX_TRIES)) {
                SignedChallenge::spend($challenge);

                return self::expired();
            }

            return response()->json(['message' => 'That code is not right'], 401);
        }

        if (SignedChallenge::consume(self::PURPOSE, $input['challenge']) === null) {
            return self::expired();
        }

        return AccountHelper::authResponse($request, $user, false, (bool)($challenge['data']['remember'] ?? false));
    }

    /**
     * @OA\Post(
     *   path="/users/signin/two-factor/passkey/options",
     *   description="Options for answering the challenge of `POST /users/signin` with one of the account's passkeys (`navigator.credentials.get`)",
     *   tags={"authentication"},
     *   security={},
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"challenge"}, additionalProperties=false, @OA\Property(property="challenge", type="string"))),
     *   @OA\Response(response="200", description="Options for the browser", @OA\JsonContent(ref="#/components/schemas/PasskeyOptionsResponse")),
     *   @OA\Response(response="401", description="The challenge has expired (`expired: true`)")
     * )
     */
    public function passkeyOptions(Request $request): JsonResponse
    {
        $input = $request->validate(['challenge' => 'required|string']);
        $challenge = SignedChallenge::read(self::PURPOSE, $input['challenge']);
        $user = $challenge === null ? null : User::find($challenge['data']['user'] ?? 0);
        if ($user === null || !$user->passkeys()->exists()) {
            return self::expired();
        }

        $options = Passkeys::requestOptionsFor($user->load('passkeys'));

        return response()->json([
            'options' => Passkeys::toArray($options),
            'challenge' => SignedChallenge::issue(self::PASSKEY_PURPOSE, ['options' => Passkeys::dump($options), 'user' => $user->id], self::TTL),
        ]);
    }

    /**
     * @OA\Post(
     *   path="/users/signin/two-factor/passkey",
     *   description="Answers the challenge of `POST /users/signin` with the credential the browser made for the options, and signs in",
     *   tags={"authentication"},
     *   security={},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       type="object",
     *       required={"challenge", "passkeyChallenge", "credential"},
     *       additionalProperties=false,
     *       @OA\Property(property="challenge", type="string", description="The one of the password step"),
     *       @OA\Property(property="passkeyChallenge", type="string", description="The one of the options response"),
     *       @OA\Property(property="credential", type="object", description="What `navigator.credentials.get` returned, as JSON", additionalProperties=true)
     *     )
     *   ),
     *   @OA\Response(response="200", description="Authentication successful", @OA\JsonContent(type="object", required={"token"}, additionalProperties=false, @OA\Property(property="token", type="string"))),
     *   @OA\Response(response="204", description="Session-based authentication successful (cookies, no token is sent)"),
     *   @OA\Response(response="401", description="The passkey did not check out, or a challenge has expired")
     * )
     */
    public function passkeyVerify(Request $request)
    {
        $input = $request->validate([
            'challenge' => 'required|string',
            'passkeyChallenge' => 'required|string',
            'credential' => 'required|array',
        ]);

        $login = SignedChallenge::read(self::PURPOSE, $input['challenge']);
        $passkeyChallenge = SignedChallenge::consume(self::PASSKEY_PURPOSE, $input['passkeyChallenge']);
        $user = $login === null ? null : User::find($login['data']['user'] ?? 0);
        if ($user === null || $passkeyChallenge === null || ($passkeyChallenge['data']['user'] ?? null) !== $user->id) {
            return self::expired();
        }

        $passkey = Passkeys::authenticate($input['credential'], Passkeys::loadRequestOptions($passkeyChallenge['data']['options']), $user->id);
        if ($passkey === null) {
            return response()->json(['message' => 'That passkey was not accepted'], 401);
        }

        if (SignedChallenge::consume(self::PURPOSE, $input['challenge']) === null) {
            return self::expired();
        }

        return AccountHelper::authResponse($request, $user, false, (bool)($login['data']['remember'] ?? false));
    }
}
