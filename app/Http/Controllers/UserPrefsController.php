<?php

namespace App\Http\Controllers;

use App\Enums\GuideName;
use App\Enums\UserPrefKey;
use App\Models\DeviantartUser;
use App\Models\User;
use App\Utils\SettingsHelper;
use App\Utils\UserPrefHelper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use OpenApi\Annotations as OA;

class UserPrefsController extends Controller
{
    /**
     * @OA\Get(
     *   path="/user-prefs/me",
     *   description="Get preferences for the current user (or defaults if none)",
     *   tags={"user prefs"},
     *   security={},
     *   @OA\Parameter(
     *     in="query",
     *     name="keys[]",
     *     required=false,
     *     description="The user preferences to return",
     *     @OA\Schema(
     *       type="array",
     *       minItems=1,
     *       @OA\Items(ref="#/components/schemas/UserPrefKeys")
     *     ),
     *   ),
     *   @OA\Response(
     *     response="200",
     *     description="Query successful",
     *     @OA\JsonContent(ref="#/components/schemas/UserPrefs")
     *   )
     * )
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {

        $valid = Validator::make($request->all(), [
            'keys' => ['sometimes', 'required', 'array', 'min:1', function ($attribute, $value, $fail) {
                $seen = [];
                foreach ($value as $key) {
                    if (!is_string($key) || UserPrefKey::tryFrom($key) === null || isset($seen[$key])) {
                        $fail('The selected keys are invalid or repeated.');
                        return;
                    }
                    $seen[$key] = true;
                }
            }],
        ])->validate();

        /** @var User $user */
        $user = $request->user();
        return response()->json(UserPrefHelper::getAll($user, $valid['keys'] ?? null));
    }

    /**
     * @OA\Schema(
     *   schema="PreferenceValue",
     *   type="object",
     *   description="The current (or newly set) value of a user preference",
     *   required={"value"},
     *   additionalProperties=false,
     *   @OA\Property(property="value", description="The preference value. Type depends on the specified preference key.")
     * )
     * @OA\Get(
     *   path="/users/{id}/preferences/{key}",
     *   operationId="GetUsersIdPreferencesKey",
     *   description="Gets the value of a preference for the specified user. Requires the requester to be the same user or staff.",
     *   tags={"users"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Parameter(in="path", name="key", required=true, @OA\Schema(ref="#/components/schemas/UserPrefKeys")),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(ref="#/components/schemas/PreferenceValue")),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Not allowed", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Unknown user or preference", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(Request $request, int $id, string $key)
    {
        [$user, $pref] = $this->resolve($request, $id, $key);

        return response()->json(['value' => UserPrefHelper::toOutput(UserPrefHelper::get($user, $pref))]);
    }

    /**
     * @OA\Put(
     *   path="/users/{id}/preferences/{key}",
     *   operationId="PutUsersIdPreferencesKey",
     *   description="Sets the value of a preference for the specified user. An empty value resets the preference. Requires the requester to be the same user or staff.",
     *   tags={"users"},
     *   @OA\Parameter(in="path", name="id", required=true, @OA\Schema(ref="#/components/schemas/OneBasedId")),
     *   @OA\Parameter(in="path", name="key", required=true, @OA\Schema(ref="#/components/schemas/UserPrefKeys")),
     *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", @OA\Property(property="value"))),
     *   @OA\Response(response="200", description="OK", @OA\JsonContent(ref="#/components/schemas/PreferenceValue")),
     *   @OA\Response(response="401", description="Not signed in", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="403", description="Not allowed", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="404", description="Unknown user or preference", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *   @OA\Response(response="422", description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse"))
     * )
     */
    public function update(Request $request, int $id, string $key)
    {
        [$user, $pref] = $this->resolve($request, $id, $key);
        if ($pref === UserPrefKey::Pcg_Slots) {
            throw new AuthorizationException("{$pref->value} is an internal setting and cannot be modified by users");
        }

        $value = UserPrefHelper::fromInput($pref, $request->input('value'));
        UserPrefHelper::set($user, $pref, $value);

        return response()->json(['value' => UserPrefHelper::toOutput(UserPrefHelper::get($user->fresh(), $pref))]);
    }

    /**
     * @return array{0: User, 1: UserPrefKey}
     */
    private function resolve(Request $request, int $id, string $key): array
    {
        $pref = UserPrefKey::tryFrom($key);
        abort_if($pref === null, 404, "Unknown preference $key");
        $user = User::findOrFail($id);

        $requester = $request->user();
        if ($requester->id !== $user->id && !$requester->isStaff()) {
            throw new AuthorizationException('You cannot access the preferences of other users');
        }

        return [$user, $pref];
    }
}
