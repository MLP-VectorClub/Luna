<?php

namespace App\Http\Controllers;

use App\Enums\GuideName;
use App\Enums\UserPrefKey;
use App\Models\DeviantartUser;
use App\Models\User;
use App\Utils\SettingsHelper;
use App\Utils\UserPrefHelper;
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
            'keys' => ['sometimes', 'required', 'array', 'distinct', 'min:1', new Enum(UserPrefKey::class)],
        ])->validate();

        /** @var User $user */
        $user = $request->user();
        return response()->json(UserPrefHelper::getAll($user, $valid['keys'] ?? null));
    }
}
