<?php

namespace App\Http\Controllers;

use App\User;
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
     * @OA\Schema(
     *     schema="UserRole",
     *     type="string",
     *     description="List of roles a user can have",
     *     enum={"guest","user","member","assistant","staff","admin","developer"},
     *     example="user",
     * )
     * @OA\Schema(
     *     schema="AvatarProvider",
     *     type="string",
     *     description="List of supported avatar providers",
     *     enum={"deviantart","discord"}
     * )
     * @OA\Schema(
     *     schema="User",
     *     type="object",
     *     description="Represents an authenticated user",
     *     required={
     *         "id",
     *         "name",
     *         "displayName",
     *         "role",
     *         "email",
     *         "avatarUrl",
     *         "avatarProvider",
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
     *         example="example",
     *     ),
     *     @OA\Property(
     *         property="displayName",
     *         type="string",
     *         example="example",
     *     ),
     *     @OA\Property(
     *         property="email",
     *         type="string",
     *         example="user@example.com",
     *         nullable=true,
     *     ),
     *     @OA\Property(
     *         property="role",
     *         ref="#/components/schemas/UserRole",
     *     ),
     *     @OA\Property(
     *         property="avatarUrl",
     *         type="string",
     *         format="uri",
     *         example="https://a.deviantart.net/avatars/e/x/example.png",
     *         nullable=true,
     *     ),
     *     @OA\Property(
     *         property="avatarProvider",
     *         ref="#/components/schemas/AvatarProvider"
     *     )
     * )
     * @OA\Schema(
     *     schema="ValueOfUser",
     *     type="object",
     *     required={
     *         "user"
     *     },
     *     additionalProperties=false,
     *     @OA\Property(
     *         property="user",
     *         ref="#/components/schemas/User"
     *     )
     * ),
     * @OA\Get(
     *     path="/users/me",
     *     description="Get information about the currently logged in user",
     *     tags={"authentication"},
     *     security={{"BearerAuth":{}},{"CookieAuth":{}}},
     *     @OA\Response(
     *         response="200",
     *         description="Query successful",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/ValueOfUser"),
     *             }
     *         )
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
    public function index(Request $request)
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    /**
     * @OA\Post(
     *     path="/users/logout",
     *     description="Shortcut for calling the token DELETE endpoint with the current token",
     *     tags={"authentication"},
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
     * @OA\Get(
     *     path="/users/tokens",
     *     description="Returns a list of access tokens that belong to the current user",
     *     tags={"authentication"},
     *     security={{"BearerAuth":{}},{"CookieAuth":{}}},
     *     @OA\Response(
     *         response="200",
     *         description="Sucess",
     *         @OA\JsonContent(
     *             additionalProperties=false,
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
