<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\DeviantartUser;
use App\Models\User;
use App\Utils\SettingsHelper;
use App\Utils\UserPrefHelper;
use Illuminate\Http\JsonResponse;
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
     *   schema="BarePublicUser",
     *   type="object",
     *   description="Represents the absolute minimum info necessary to get a user profile URL",
     *   required={
     *     "id",
     *     "name",
     *     "role",
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="id",
     *     type="integer",
     *     minimum=1,
     *     example=1,
     *   ),
     *   @OA\Property(
     *     property="name",
     *     type="string",
     *     example="example",
     *   ),
     *   @OA\Property(
     *     property="role",
     *     description="The publicly visible role for the user",
     *     ref="#/components/schemas/Role",
     *   ),
     * )
     * @OA\Schema(
     *   schema="PublicUser",
     *   allOf={
     *     @OA\Schema(ref="#/components/schemas/BarePublicUser"),
     *     @OA\Schema(
     *       type="object",
     *       description="Represents a publicly accessible representation of a user",
     *       required={
     *         "avatarUrl",
     *         "avatarProvider",
     *       },
     *       additionalProperties=false,
     *       @OA\Property(
     *         property="avatarUrl",
     *         type="string",
     *         format="uri",
     *         example="https://a.deviantart.net/avatars/e/x/example.png",
     *         nullable=true,
     *       ),
     *       @OA\Property(
     *         property="avatarProvider",
     *         ref="#/components/schemas/AvatarProvider"
     *       ),
     *     )
     *   }
     * )
     * @OA\Schema(
     *   schema="User",
     *   allOf={
     *     @OA\Schema(ref="#/components/schemas/PublicUser"),
     *     @OA\Schema(
     *       type="object",
     *       description="Represents an authenticated user",
     *       required={
     *         "email",
     *         "role",
     *       },
     *       additionalProperties=false,
     *       @OA\Property(
     *         property="email",
     *         type="string",
     *         example="user@example.com",
     *         nullable=true,
     *       ),
     *       @OA\Property(
     *         property="role",
     *         description="The database-level role for the user",
     *         ref="#/components/schemas/DatabaseRole",
     *       ),
     *     )
     *   }
     * )
     * @OA\Get(
     *   path="/users/me",
     *   description="Get information about the currently logged in user",
     *   tags={"authentication","users"},
     *   security={{"BearerAuth":{}},{"CookieAuth":{}}},
     *   @OA\Response(
     *     response="200",
     *     description="Query successful",
     *     @OA\JsonContent(ref="#/components/schemas/User")
     *   ),
     *   @OA\Response(
     *     response="401",
     *     description="Unathorized",
     *   )
     * )
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        return response()->camelJson($user->toArrayWithProtected());
    }

    /**
     * @OA\Get(
     *   path="/users/da/{username}",
     *   description="Get on-site user information via a DeviantArt username",
     *   tags={"users"},
     *   security={},
     *   @OA\Parameter(
     *     in="path",
     *     name="username",
     *     required=true,
     *     @OA\Schema(
     *       type="string"
     *     ),
     *     description="The DeviantArt username to look for"
     *   ),
     *   @OA\Response(
     *     response="200",
     *     description="Query successful",
     *     @OA\JsonContent(ref="#/components/schemas/PublicUser")
     *   ),
     *   @OA\Response(
     *     response="404",
     *     description="No user found by this name"
     *   ),
     *   @OA\Response(
     *     response="401",
     *     description="Unathorized",
     *   )
     * )
     *
     * @param  Request  $request
     * @param  string   $username
     * @return JsonResponse
     */
    public function getByName(Request $request, string $username): JsonResponse
    {
        /** @var DeviantartUser $da_user */
        $da_user = DeviantartUser::where('name', $username)->firstOrFail();
        /** @var User $user */
        $user = $da_user->user()->firstOrFail();
        return $this->getById($request, $user);
    }

    /**
     * @OA\Get(
     *   path="/users/{id}",
     *   description="Get information about the specified user",
     *   tags={"users"},
     *   security={},
     *   @OA\Parameter(
     *     in="path",
     *     name="id",
     *     required=true,
     *     @OA\Schema(ref="#/components/schemas/OneBasedId")
     *   ),
     *   @OA\Response(
     *     response="200",
     *     description="Query successful",
     *     @OA\JsonContent(ref="#/components/schemas/PublicUser")
     *   ),
     *   @OA\Response(
     *     response="404",
     *     description="No user found by this ID"
     *   ),
     *   @OA\Response(
     *     response="401",
     *     description="Unathorized",
     *   )
     * )
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function getById(Request $request, User $user): JsonResponse
    {
        return response()->camelJson($user->publicResponse());
    }

    /**
     * @OA\Get(
     *   path="/users",
     *   description="Get a full list of users, i.e. those that have the 'user' role (requires staff permissions)",
     *   tags={"users"},
     *   security={{"BearerAuth":{}},{"CookieAuth":{}}},
     *   @OA\Response(
     *     response="200",
     *     description="Query successful",
     *     @OA\JsonContent(
     *       type="array",
     *       @OA\Items(ref="#/components/schemas/BarePublicUser")
     *     )
     *   ),
     *   @OA\Response(
     *     response="401",
     *     description="Unathorized",
     *   )
     * )
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function list(Request $request, User $user): JsonResponse
    {
        if (!perm(Role::Staff())) {
            abort(401);
        }

        $fetch_role = Role::User;
        $roles = [$fetch_role];
        $dev_role_label = SettingsHelper::get('dev_role_label');
        if ($dev_role_label === $fetch_role) {
            $roles[] = Role::Developer;
        }
        $users = User::whereIn('role', $roles)->orderBy('name')->get(['id', 'name']);

        return response()->camelJson($users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'role' => $fetch_role,
        ]));
    }

    /**
     * @OA\Post(
     *   path="/users/signout",
     *   description="Shortcut for calling the token DELETE endpoint with the current token",
     *   tags={"authentication","users"},
     *   security={{"BearerAuth":{}},{"CookieAuth":{}}},
     *   @OA\Response(
     *     response="204",
     *     description="Signout successful"
     *   ),
     *   @OA\Response(
     *     response="401",
     *     description="Unathorized",
     *   )
     * )
     *
     * @param  Request  $request
     * @return Response
     */
    public function signout(Request $request): Response
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
     *   schema="Token",
     *   type="object",
     *   required={
     *     "id",
     *     "name",
     *     "lastUsedAt",
     *     "createdAt"
     *   },
     *   additionalProperties=false,
     *   @OA\Property(
     *     property="id",
     *     type="integer",
     *     minimum=1,
     *     example=1,
     *   ),
     *   @OA\Property(
     *     property="name",
     *     type="string",
     *     description="Name of the token, either generated (from OS and browser version) or user-supplied if renamed",
     *   ),
     *   @OA\Property(
     *     property="lastUsedAt",
     *     ref="#/components/schemas/IsoStandardDate"
     *   ),
     *   @OA\Property(
     *     property="createdAt",
     *     ref="#/components/schemas/IsoStandardDate"
     *   ),
     * )
     *
     * @OA\Get(
     *   path="/users/tokens",
     *   description="Returns a list of access tokens that belong to the current user",
     *   tags={"authentication","users"},
     *   security={{"BearerAuth":{}},{"CookieAuth":{}}},
     *   @OA\Response(
     *     response="200",
     *     description="Success",
     *     @OA\JsonContent(
     *       required={
     *         "currentTokenId",
     *         "tokens",
     *       },
     *       additionalProperties=false,
     *       @OA\Property(
     *         property="currentTokenId",
     *         description="ID of the token used to make this request. Will be null if the request is authenticated through CookieAuth",
     *         type="integer",
     *         minimum=1,
     *         example=1,
     *         nullable=true,
     *       ),
     *       @OA\Property(
     *         property="tokens",
     *         description="A list of tokens that belong to the user",
     *         type="array",
     *         minItems=1,
     *         @OA\Items(ref="#/components/schemas/Token")
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response="401",
     *     description="Unathorized",
     *   )
     * )
     * @param  Request  $request
     * @return JsonResponse
     */
    public function tokens(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken|TransientToken $current_token */
        $current_token = $user->currentAccessToken();

        return response()->camelJson([
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
     *   path="/users/tokens/{id}",
     *   description="Deletes an access token that belongs to the current user",
     *   tags={"authentication"},
     *   security={{"BearerAuth":{}},{"CookieAuth":{}}},
     *   @OA\Parameter(
     *     in="path",
     *     name="id",
     *     required=true,
     *     @OA\Schema(
     *       type="integer",
     *       minimum=1,
     *       example=1
     *     ),
     *     description="The ID of the token to delete"
     *   ),
     *   @OA\Response(
     *     response="204",
     *     description="Success"
     *   ),
     *   @OA\Response(
     *     response="404",
     *     description="Token not found",
     *   ),
     *   @OA\Response(
     *     response="401",
     *     description="Unathorized",
     *   )
     * )
     * @param  int  $token_id
     * @param  Request  $request
     * @return Response
     */
    public function deleteToken(int $token_id, Request $request): Response
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
