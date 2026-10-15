<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Utils\AccountHelper;
use App\Utils\Passkeys;
use App\Utils\SignedChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

/**
 * Signing in with a passkey alone, no password: the browser offers the passkeys it holds for the site (a button, or the sign-in form's address field
 * as autofill) and a passkey proves the person holds the device and unlocked it. The options are not tied to anybody, and travel as an encrypted token
 * (see `SignedChallenge`) so that a visitor who only opens the dialog leaves nothing behind on the server
 *
 * @OA\Schema(
 *   schema="PasskeyOptionsResponse",
 *   type="object",
 *   required={"options", "challenge"},
 *   additionalProperties=false,
 *   @OA\Property(property="options", type="object", additionalProperties=true, description="The `publicKey` options for `navigator.credentials.get` or `.create`, as JSON (binary values are base64url)"),
 *   @OA\Property(property="challenge", type="string", description="Send this back with the credential")
 * )
 */
class PasskeySigninController extends Controller
{
    public const PURPOSE = 'passkey-signin';

    /**
     * @OA\Post(
     *   path="/users/signin/passkey/options",
     *   description="Options for signing in with a passkey (`navigator.credentials.get`), good for ten minutes: browsers keep a conditional (autofill) request open while the visitor types",
     *   tags={"authentication"},
     *   security={},
     *   @OA\Response(response="200", description="Options for the browser", @OA\JsonContent(ref="#/components/schemas/PasskeyOptionsResponse"))
     * )
     */
    public function options(): JsonResponse
    {
        $options = Passkeys::requestOptions();

        return response()->json([
            'options' => Passkeys::toArray($options),
            'challenge' => SignedChallenge::issue(self::PURPOSE, ['options' => Passkeys::dump($options)], config('webauthn.challenge_ttl')),
        ]);
    }

    /**
     * @OA\Post(
     *   path="/users/signin/passkey",
     *   description="Signs in with the credential the browser made for the options",
     *   tags={"authentication"},
     *   security={},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       type="object",
     *       required={"challenge", "credential"},
     *       additionalProperties=false,
     *       @OA\Property(property="challenge", type="string"),
     *       @OA\Property(property="credential", type="object", description="What `navigator.credentials.get` returned, as JSON", additionalProperties=true),
     *       @OA\Property(property="remember", type="boolean")
     *     )
     *   ),
     *   @OA\Response(response="200", description="Authentication successful", @OA\JsonContent(type="object", required={"token"}, additionalProperties=false, @OA\Property(property="token", type="string"))),
     *   @OA\Response(response="204", description="Session-based authentication successful (cookies, no token is sent)"),
     *   @OA\Response(response="401", description="The passkey did not check out, or the options have expired or were used (`expired: true`)"),
     *   @OA\Response(response="403", description="Could not sign in, check the error message for details", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function verify(Request $request)
    {
        $input = $request->validate([
            'challenge' => 'required|string',
            'credential' => 'required|array',
            'remember' => 'sometimes|boolean',
        ]);

        $challenge = SignedChallenge::consume(self::PURPOSE, $input['challenge']);
        if ($challenge === null) {
            return response()->json(['message' => 'This sign-in has expired, please try again', 'expired' => true], 401);
        }

        $passkey = Passkeys::authenticate($input['credential'], Passkeys::loadRequestOptions($challenge['data']['options']));
        $user = $passkey === null ? null : User::find($passkey->user_id);
        if ($user === null) {
            return response()->json(['message' => 'That passkey was not accepted'], 401);
        }

        if ($blocked = SigninController::deviantArtBlock($user)) {
            return $blocked;
        }

        return AccountHelper::authResponse($request, $user, false, (bool)($input['remember'] ?? false));
    }
}
