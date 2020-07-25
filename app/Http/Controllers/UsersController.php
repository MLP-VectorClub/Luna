<?php

namespace App\Http\Controllers;

use App\DeviantartUser;
use App\Models\User;
use App\Utils\SettingsHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use OpenApi\Annotations as OA;

class UsersController extends Controller
{
    /**
     * @OA\Get(
     *     path="/users/me",
     *     description="Get information about the currently logged in user",
     *     tags={"authentication","users"},
     *     security={{"BearerAuth":{}},{"CookieAuth":{}}},
     *     @OA\Response(
     *         response="200",
     *         description="Query successful",
     *         @OA\JsonContent(ref="#/components/schemas/User")
     *     ),
     *     @OA\Response(
     *         response="401",
     *         description="Unathorized",
     *     )
     * )
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        return response()->json($user->toArrayWithProtected());
    }

    /**
     * @OA\Get(
     *     path="/users/{username}",
     *     description="Get information about the specified user",
     *     tags={"users"},
     *     @OA\Parameter(
     *         in="path",
     *         name="username",
     *         required=true,
     *         @OA\Schema(
     *             type="string"
     *         ),
     *         description="The DeviantArt username to look for"
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="Query successful",
     *         @OA\JsonContent(ref="#/components/schemas/PublicUser")
     *     ),
     *     @OA\Response(
     *         response="404",
     *         description="No user found by this name"
     *     ),
     *     @OA\Response(
     *         response="401",
     *         description="Unathorized",
     *     )
     * )
     *
     * @param  Request  $request
     * @param  string   $username
     * @return \Illuminate\Http\JsonResponse
     */
    public function getByName(Request $request, string $username)
    {
        /** @var User $user */
        $user = User::where('name', $username)->firstOrFail();
        return response()->json($user->publicResponse());
    }

    /**
     * @OA\Post(
     *     path="/users/logout",
     *     description="Shortcut for calling the token DELETE endpoint with the current token",
     *     tags={"authentication","users"},
     *     security={{"BearerAuth":{}},{"CookieAuth":{}}},
     *     @OA\Response(
     *         response="204",
     *         description="Logout successful"
     *     ),
     *     @OA\Response(
     *         response="401",
     *         description="Unathorized",
     *     )
     * )
     *
     * @param  Request  $request
     * @return Response
     */
    public function logout(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken|TransientToken|null */
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logoutCurrentDevice();
        }

        return response()->noContent();
    }

    /**
     * @OA\Schema(
     *     schema="Token",
     *     type="object",
     *     required={
     *         "id",
     *         "name",
     *         "lastUsedAt",
     *         "createdAt"
     *     },
     *     additionalProperties=false,
     *     @OA\Property(
     *         property="id",
     *         type="integer",
     *         minimum=1,
     *         example=1,
     *     ),
     *     @OA\Property(
     *         property="name",
     *         type="string",
     *         description="Name of the token, either generated (from OS and browser version) or user-supplied if renamed",
     *     ),
     *     @OA\Property(
     *         property="lastUsedAt",
     *         type="string",
     *         format="date-time",
     *     ),
     *     @OA\Property(
     *         property="createdAt",
     *         type="string",
     *         format="date-time",
     *     ),
     * )
     *
     * @OA\Get(
     *     path="/users/tokens",
     *     description="Returns a list of access tokens that belong to the current user",
     *     tags={"authentication","users"},
     *     security={{"BearerAuth":{}},{"CookieAuth":{}}},
     *     @OA\Response(
     *         response="200",
     *         description="Sucess",
     *         @OA\JsonContent(
     *             required={
     *                 "currentTokenId",
     *                 "tokens",
     *             },
     *             additionalProperties=false,
     *             @OA\Property(
     *                 property="currentTokenId",
     *                 description="ID of the token used to make this request. Will be null if the request is authenticated through CookieAuth",
     *                 type="integer",
     *                 minimum=1,
     *                 example=1,
     *                 nullable=true,
     *             ),
     *             @OA\Property(
     *                 property="tokens",
     *                 description="A list of tokens that belong to the user",
     *                 type="array",
     *                 minItems=1,
     *                 @OA\Items(ref="#/components/schemas/Token")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response="401",
     *         description="Unathorized",
     *     )
     * )
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function tokens(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken|TransientToken $current_token */
        $current_token = $user->currentAccessToken();

        return response()->json([
            'current_token_id' => $current_token->id ?? null,
            'tokens' => $user->tokens->map(function (PersonalAccessToken $t) {
                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'lastUsedAt' => Date::maybeToString($t->last_used_at),
                    'createdAt' => Date::maybeToString($t->created_at),
                ];
            })
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/users/tokens/{id}",
     *     description="Deletes an access token that belongs to the current user",
     *     tags={"authentication"},
     *     security={{"BearerAuth":{}},{"CookieAuth":{}}},
     *     @OA\Parameter(
     *         in="path",
     *         name="id",
     *         required=true,
     *         @OA\Schema(
     *             type="integer",
     *             minimum=1,
     *             example=1
     *         ),
     *         description="The ID of the token to delete"
     *     ),
     *     @OA\Response(
     *         response="204",
     *         description="Sucess"
     *     ),
     *     @OA\Response(
     *         response="404",
     *         description="Token not found",
     *     ),
     *     @OA\Response(
     *         response="401",
     *         description="Unathorized",
     *     )
     * )
     * @param  int  $token_id
     * @param  Request  $request
     * @return Response
     */
    public function deleteToken(int $token_id, Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken $token */
        $token = $user->tokens->where('id', $token_id)->first();
        if ($token === null) {
            abort(404);
        }

        $token->delete();

        return response()->noContent();
    }
}
