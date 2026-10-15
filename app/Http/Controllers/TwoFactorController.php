<?php

namespace App\Http\Controllers;

use App\Models\RecoveryCode;
use App\Models\TwoFactor;
use App\Models\User;
use App\Utils\IdentityCheck;
use App\Utils\TwoFactorAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;

/**
 * The authenticator app (TOTP) of the signed in account. Setting one up is two steps (a secret to scan, then a code of the app to prove it works), and
 * only a confirmed one is asked for at sign-in. Changes ask for the current password again (or a code, when the account has no password)
 */
class TwoFactorController extends Controller
{
    /**
     * @OA\Get(
     *   path="/users/me/two-factor",
     *   description="Whether the account has two-factor authentication",
     *   tags={"account"},
     *   @OA\Response(response="200", description="Status", @OA\JsonContent(
     *     type="object",
     *     required={"enabled", "recoveryCodesRemaining", "passwordSet"},
     *     additionalProperties=false,
     *     @OA\Property(property="enabled", type="boolean"),
     *     @OA\Property(property="passwordSet", type="boolean", description="Whether changes are confirmed with the password (else with a code of the app, or not at all)"),
     *     @OA\Property(property="recoveryCodesRemaining", type="integer", minimum=0)
     *   )),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'enabled' => $user->hasTwoFactor(),
            'recoveryCodesRemaining' => TwoFactorAuth::remainingRecoveryCodes($user),
            'passwordSet' => $user->password !== null,
        ]);
    }

    /**
     * @OA\Post(
     *   path="/users/me/two-factor",
     *   description="Starts setting up an authenticator app: a new secret (replacing an earlier setup that was never confirmed) to scan or type in. Nothing is asked at sign-in until `POST /users/me/two-factor/confirm`",
     *   tags={"account"},
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", additionalProperties=false, @OA\Property(property="currentPassword", type="string"), @OA\Property(property="code", type="string"))),
     *   @OA\Response(response="200", description="The secret", @OA\JsonContent(
     *     type="object",
     *     required={"secret", "uri"},
     *     additionalProperties=false,
     *     @OA\Property(property="secret", type="string", description="Base32, for typing into the app"),
     *     @OA\Property(property="uri", type="string", description="`otpauth://` address for a QR code")
     *   )),
     *   @OA\Response(response="409", description="Already set up, disable it first", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="The password (or code) is missing or wrong", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function start(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        IdentityCheck::confirm($request, $user);

        if ($user->hasTwoFactor()) {
            return response()->json(['message' => 'Two-factor authentication is already set up'], 409);
        }

        $secret = TwoFactorAuth::newSecret();
        TwoFactor::updateOrCreate(['user_id' => $user->id], ['secret' => $secret, 'confirmed_at' => null, 'last_used_step' => null]);

        return response()->json(['secret' => $secret, 'uri' => TwoFactorAuth::uri($user, $secret)]);
    }

    /**
     * @OA\Post(
     *   path="/users/me/two-factor/confirm",
     *   description="Finishes the setup with a code of the app and turns two-factor authentication on. The recovery codes are shown only here",
     *   tags={"account"},
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", required={"code"}, additionalProperties=false, @OA\Property(property="code", type="string"))),
     *   @OA\Response(response="200", description="Turned on", @OA\JsonContent(
     *     type="object",
     *     required={"recoveryCodes"},
     *     additionalProperties=false,
     *     @OA\Property(property="recoveryCodes", type="array", @OA\Items(type="string"))
     *   )),
     *   @OA\Response(response="409", description="No setup was started, or it is confirmed already", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="The code is wrong", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function confirm(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $input = $request->validate(['code' => 'required|string|max:64']);

        $factor = TwoFactor::where('user_id', $user->id)->first();
        if ($factor === null || $factor->confirmed_at !== null) {
            return response()->json(['message' => 'There is no setup to confirm'], 409);
        }
        if (!TwoFactorAuth::verifyCode($factor, $input['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is not right']);
        }

        $factor->forceFill(['confirmed_at' => now()])->save();
        $codes = TwoFactorAuth::replaceRecoveryCodes($user);
        IdentityCheck::endOtherSessions($request, $user);

        return response()->json(['recoveryCodes' => $codes]);
    }

    /**
     * @OA\Post(
     *   path="/users/me/two-factor/recovery-codes",
     *   description="Replaces the recovery codes with new ones (the old ones stop working), shown only here",
     *   tags={"account"},
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", additionalProperties=false, @OA\Property(property="currentPassword", type="string"), @OA\Property(property="code", type="string"))),
     *   @OA\Response(response="200", description="The new codes", @OA\JsonContent(type="object", required={"recoveryCodes"}, additionalProperties=false, @OA\Property(property="recoveryCodes", type="array", @OA\Items(type="string")))),
     *   @OA\Response(response="409", description="Two-factor authentication is not on", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="The password (or code) is missing or wrong", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function recoveryCodes(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (!$user->hasTwoFactor()) {
            return response()->json(['message' => 'Two-factor authentication is not on'], 409);
        }
        IdentityCheck::confirm($request, $user);

        return response()->json(['recoveryCodes' => TwoFactorAuth::replaceRecoveryCodes($user)]);
    }

    /**
     * @OA\Delete(
     *   path="/users/me/two-factor",
     *   description="Turns two-factor authentication off. Every other session and token of the account ends",
     *   tags={"account"},
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", additionalProperties=false, @OA\Property(property="currentPassword", type="string"), @OA\Property(property="code", type="string"))),
     *   @OA\Response(response="204", description="Turned off"),
     *   @OA\Response(response="422", description="The password (or code) is missing or wrong", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function disable(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        IdentityCheck::confirm($request, $user);

        TwoFactor::where('user_id', $user->id)->delete();
        RecoveryCode::where('user_id', $user->id)->delete();
        IdentityCheck::endOtherSessions($request, $user);

        return response()->noContent();
    }
}
