<?php

namespace App\Http\Controllers;

use App\Models\DeviantartUser;
use App\Models\User;
use App\Utils\SettingsHelper;
use App\Utils\UserPrefHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
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
        /** @var User $user */
        $user = $request->user();
        return response()->json(UserPrefHelper::getAll($user));
    }
}
